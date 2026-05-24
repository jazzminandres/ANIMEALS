<?php
// THIS FILE CREATES AND TRACKS CHECKOUT PAYMENTS FOR STUDENT ORDERS.
require_once __DIR__ . '/session_config.php';
session_start();
require_once __DIR__ . '/db.php';

if (!isset($_SESSION['user'])) {
    header("Location: index.php");
    exit();
}

$serverName = "SatanaelLG\MSSQLSERVER01";
$connOptions = ["Database" => "ANIMEALS", "Uid" => "", "PWD" => ""];
$conn = mysqlsrv_connect($serverName, $connOptions);
$connSeller = mysqlsrv_connect($serverName, array_merge($connOptions, ["Database" => "SELLER_DATA"]));

require_once __DIR__ . '/schema_bootstrap.php';
// MAKE SURE ORDER ITEM NOTES AND CHECKOUT SUPPORT COLUMNS EXIST BEFORE PLACING ORDERS.
animeals_ensure_extensions($conn);
if ($connSeller) {
    seller_data_ensure_shop_type($connSeller);
}

$PAYMONGO_SECRET_KEY = '.';
$PAYMONGO_PUBLIC_KEY = 'pk_test_KFaXyzqyphvUkTpyY7zx5inp';

function getBaseUrl(): string {
    // BUILD THE CURRENT SITE BASE URL SO PAYMONGO CAN SEND USERS BACK TO THIS APP.
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    $path = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
    return $scheme . '://' . $host . $path . '/';
}

function createPaymongoCheckoutSession(string $secretKey, string $type, int $amount, string $currency, string $successUrl, string $failedUrl, array $billing): array {
    // CREATE A PAYMONGO CHECKOUT SESSION SO CARD AND E-WALLET PAYMENTS USE THE SAME REDIRECT FLOW.
    $method = strtolower($type) === 'card' ? 'card' : 'gcash';
    $payload = [
        'data' => [
            'attributes' => [
                'send_email_receipt' => false,
                'show_description' => true,
                'show_line_items' => true,
                'description' => 'Animeals order payment',
                'payment_method_types' => [$method],
                'success_url' => $successUrl,
                'cancel_url' => $failedUrl,
                'line_items' => [
                    [
                        'currency' => $currency,
                        'amount' => $amount,
                        'name' => 'Animeals order',
                        'quantity' => 1,
                    ],
                ],
                'billing' => [
                    'name' => $billing['name'] ?? '',
                    'email' => $billing['email'] ?? '',
                ],
                'metadata' => [
                    'payment_method' => $billing['payment_method'] ?? '',
                    'order_note' => $billing['order_note'] ?? '',
                ],
            ],
        ],
    ];

    if (!function_exists('curl_version')) {
        // PAYMONGO CALLS NEED CURL, SO FAIL CLEANLY IF THE SERVER DOES NOT HAVE IT ENABLED.
        return ['success' => false, 'error' => 'cURL is not available on the server.'];
    }

    $ch = curl_init('https://api.paymongo.com/v1/checkout_sessions');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
    curl_setopt($ch, CURLOPT_USERPWD, $secretKey . ':');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        $error = curl_error($ch);
        curl_close($ch);
        return ['success' => false, 'error' => $error];
    }

    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = json_decode($response, true);
    if ($status >= 200 && $status < 300 && isset($decoded['data']['attributes']['checkout_url'])) {
        return [
            'success' => true,
            'checkout_url' => $decoded['data']['attributes']['checkout_url'],
            'checkout_session_id' => $decoded['data']['id'] ?? '',
        ];
    }

    $error = $decoded['errors'][0]['detail'] ?? ($decoded['message'] ?? 'Unable to create PayMongo checkout session.');
    return ['success' => false, 'error' => $error];
}

// LOAD THE STUDENT BEFORE READING CART ITEMS.
$stmt = mysqlsrv_query($conn, "SELECT * FROM USER_DETAILS WHERE userEMAIL = ?", [$_SESSION['email']]);
$user = mysqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
$studentID = (int)($user['userID'] ?? 0);

// FETCH THE FULL CART WITH MENU AND SHOP DETAILS SO TOTALS CAN BE CALCULATED SERVER-SIDE.
$cartStmt = mysqlsrv_query($conn,
    "SELECT c.*, mi.itemName, mi.itemPrice, mi.itemImage, ss.shopName, ss.shopID
     FROM CART c
     JOIN SELLER_DATA.dbo.MENU_ITEMS mi ON mi.itemID = c.itemID
     JOIN SELLER_DATA.dbo.SELLER_SHOPS ss ON ss.shopID = c.shopID
     WHERE c.studentID = ?",
    [$studentID]
);
$cartItems = [];
$total = 0;
while ($row = mysqlsrv_fetch_array($cartStmt, SQLSRV_FETCH_ASSOC)) {
    $cartItems[] = $row;
    $total += (float)$row['itemPrice'] * (int)$row['quantity'];
}

if (empty($cartItems)) {
    header("Location: student.php");
    exit();
}

/* -- HANDLE CHECKOUT SUBMIT -- */
$paymongoError = '';
if (isset($_GET['paymongo_failed']) && isset($_GET['token']) && isset($_SESSION['paymongo_pending']['returnToken']) && $_GET['token'] === $_SESSION['paymongo_pending']['returnToken']) {
    $paymongoError = 'PayMongo payment was canceled or failed. Please try again or choose Cash.';
}
$checkoutError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['placeOrder'])) {
    // CHECKOUT EITHER STARTS PAYMONGO OR CREATES CASH ORDERS IMMEDIATELY.
    $paymentMethod = trim($_POST['paymentMethod'] ?? 'Cash');
    $orderNote     = trim($_POST['orderNote'] ?? '');
    $deliveryLat = isset($_POST['deliveryLat']) && is_numeric($_POST['deliveryLat']) ? (float) $_POST['deliveryLat'] : null;
    $deliveryLng = isset($_POST['deliveryLng']) && is_numeric($_POST['deliveryLng']) ? (float) $_POST['deliveryLng'] : null;
    $deliveryAddress = trim((string) ($_POST['deliveryAddress'] ?? ''));

    if ($deliveryLat === null || $deliveryLng === null) {
        $checkoutError = 'Please pin your delivery location on the map before placing your order.';
    } elseif (in_array($paymentMethod, ['GCash', 'Card'], true)) {
        // SAVE A RETURN TOKEN IN SESSION SO THE PAYMONGO RETURN PAGE CAN VERIFY THE CALLBACK.
        $returnToken = bin2hex(random_bytes(16));
        $_SESSION['paymongo_pending'] = [
            'paymentMethod' => $paymentMethod,
            'orderNote' => $orderNote,
            'deliveryLat' => $deliveryLat,
            'deliveryLng' => $deliveryLng,
            'deliveryAddress' => $deliveryAddress,
            'returnToken' => $returnToken,
        ];

        $successUrl = getBaseUrl() . 'paymongo_return.php?token=' . urlencode($returnToken);
        $failedUrl = getBaseUrl() . 'payment.php?paymongo_failed=1&token=' . urlencode($returnToken);
        $amountCents = (int)round($total * 100);
        $billing = ['name' => $user['userNAME'], 'email' => $user['userEMAIL'], 'payment_method' => $paymentMethod, 'order_note' => $orderNote];

        $sourceResult = createPaymongoCheckoutSession($PAYMONGO_SECRET_KEY, $paymentMethod, $amountCents, 'PHP', $successUrl, $failedUrl, $billing);
        if ($sourceResult['success']) {
            $_SESSION['paymongo_pending']['checkoutSessionID'] = $sourceResult['checkout_session_id'] ?? '';
            header('Location: ' . $sourceResult['checkout_url']);
            exit();
        }

        $paymongoError = 'Unable to start PayMongo checkout: ' . htmlspecialchars($sourceResult['error'], ENT_QUOTES, 'UTF-8');
    } else {
        $paymentMethod = 'Cash';

        // GROUP CART BY SHOP SO EACH SELLER RECEIVES A SEPARATE ORDER.
        $byShop = [];
        foreach ($cartItems as $item) {
            $byShop[(int)$item['shopID']][] = $item;
        }

        foreach ($byShop as $shopID => $items) {
            $shopTotal = array_sum(array_map(fn($i) => (float)$i['itemPrice'] * (int)$i['quantity'], $items));

            // Insert order
            mysqlsrv_query($conn,
                "INSERT INTO ORDERS (studentID, shopID, totalAmount, paymentMethod, orderNote, deliveryLAT, deliveryLNG, deliveryADDRESS, orderStatus)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')",
                [$studentID, $shopID, $shopTotal, $paymentMethod, $orderNote, $deliveryLat, $deliveryLng, $deliveryAddress !== '' ? $deliveryAddress : null]
            );

            // Get the new orderID
            $newOrderStmt = mysqlsrv_query($conn,
                "SELECT TOP 1 orderID FROM ORDERS WHERE studentID = ? AND shopID = ? ORDER BY orderedAt DESC",
                [$studentID, $shopID]
            );
            $newOrder = mysqlsrv_fetch_array($newOrderStmt, SQLSRV_FETCH_ASSOC);
            $orderID  = (int)($newOrder['orderID'] ?? 0);

            // Insert order items (per-line notes for seller / exports)
            foreach ($items as $item) {
                $lineNote = trim((string) ($item['lineNote'] ?? ''));
                mysqlsrv_query(
                    $conn,
                    "INSERT INTO ORDER_ITEMS (orderID, itemID, itemName, quantity, price, lineNote)
                     VALUES (?, ?, ?, ?, ?, ?)",
                    [
                        $orderID,
                        $item['itemID'],
                        $item['itemName'],
                        $item['quantity'],
                        $item['itemPrice'],
                        $lineNote !== '' ? $lineNote : null,
                    ]
                );
            }
        }

        // Clear cart
        mysqlsrv_query($conn, "DELETE FROM CART WHERE studentID = ?", [$studentID]);

        $_SESSION['checkout_success'] = true;
        header('Location: student.php');
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Animeals Checkout</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <style>
        body { font-family: 'Inter', sans-serif; }
        #checkoutMap { height: 260px; border-radius: 18px; border: 1px solid #d1fae5; overflow: hidden; }
    </style>
</head>
<body class="bg-gradient-to-br from-green-400 via-emerald-500 to-teal-600 min-h-screen flex items-center justify-center p-4">

    <div class="bg-white w-full max-w-4xl rounded-2xl shadow-2xl overflow-hidden p-8 md:p-16">

        <div class="text-center mb-10">
            <h1 class="text-3xl font-bold text-gray-800 tracking-tight">ANIMEALS CHECKOUT</h1>
            <p class="text-gray-400 mt-2 text-sm">Review your order and complete payment</p>
        </div>

        <?php if (!empty($paymongoError)): ?>
            <div class="mb-6 rounded-xl border border-red-200 bg-red-50 px-5 py-4 text-sm text-red-700"><?= htmlspecialchars($paymongoError, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <?php if (!empty($checkoutError)): ?>
            <div class="mb-6 rounded-xl border border-red-200 bg-red-50 px-5 py-4 text-sm text-red-700"><?= htmlspecialchars($checkoutError, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <form method="POST" id="checkoutForm">
            <div class="flex flex-col md:flex-row gap-12">

                <!-- Left: Payment method -->
                <div class="flex-1">
                    <h2 class="text-xl font-bold text-gray-700 mb-6">Payment Method</h2>

                    <div class="grid grid-cols-3 gap-3 mb-8">
                        <label class="cursor-pointer">
                            <input type="radio" name="paymentMethod" value="Cash" class="peer hidden" checked>
                            <div class="text-center p-3 border-2 rounded-xl border-gray-100 peer-checked:border-emerald-500 peer-checked:bg-emerald-50 transition-all">
                                <span class="block text-xs font-bold text-gray-600">CASH</span>
                            </div>
                        </label>
                        <label class="cursor-pointer">
                            <input type="radio" name="paymentMethod" value="GCash" class="peer hidden">
                            <div class="text-center p-3 border-2 rounded-xl border-gray-100 peer-checked:border-blue-500 peer-checked:bg-blue-50 transition-all">
                                <span class="block text-xs font-bold text-gray-600">GCASH</span>
                            </div>
                        </label>
                        <label class="cursor-pointer">
                            <input type="radio" name="paymentMethod" value="Card" class="peer hidden">
                            <div class="text-center p-3 border-2 rounded-xl border-gray-100 peer-checked:border-violet-500 peer-checked:bg-violet-50 transition-all">
                                <span class="block text-xs font-bold text-gray-600">CARD</span>
                            </div>
                        </label>
                    </div>

                    <p class="text-xs text-gray-500 mb-6">Selecting GCash or Card will redirect you to PayMongo to complete the payment securely.</p>

                    <div class="mb-4">
                        <label class="block text-xs uppercase font-bold text-gray-400 mb-1">Order Note (optional)</label>
                        <textarea name="orderNote" placeholder="Any special instructions..."
                                  class="w-full p-3 bg-gray-50 border border-gray-200 rounded-lg outline-none resize-none" rows="3"></textarea>
                    </div>

                    <div class="mb-4">
                        <label class="block text-xs uppercase font-bold text-gray-400 mb-1">Delivery Location</label>
                        <input type="text" name="deliveryAddress" id="deliveryAddress" placeholder="Room, building, gate, or landmark"
                               value="<?= htmlspecialchars($_POST['deliveryAddress'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                               class="w-full p-3 bg-gray-50 border border-gray-200 rounded-lg outline-none mb-3">
                        <input type="hidden" name="deliveryLat" id="deliveryLat">
                        <input type="hidden" name="deliveryLng" id="deliveryLng">
                        <div id="checkoutMap"></div>
                        <div class="flex gap-2 mt-3">
                            <button type="button" onclick="checkoutUseCurrentLocation()" class="flex-1 bg-emerald-50 border border-emerald-200 text-emerald-700 font-bold py-2 rounded-xl text-xs">Use my current location</button>
                            <button type="button" onclick="checkoutClearLocation()" class="bg-gray-100 text-gray-500 font-bold py-2 px-4 rounded-xl text-xs">Clear</button>
                        </div>
                        <p id="checkoutLocationHelp" class="text-xs text-gray-400 mt-2">Required: tap the map or use your current location to pin where the seller should deliver your order.</p>
                    </div>

                    <div class="text-sm text-gray-500 mb-2">
                        <b>Name:</b> <?= htmlspecialchars($user['userNAME']) ?> &nbsp;|&nbsp;
                        <b>Email:</b> <?= htmlspecialchars($user['userEMAIL']) ?>
                    </div>
                </div>

                <!-- Right: Order summary -->
                <div class="w-full md:w-80 bg-emerald-50/40 p-6 rounded-2xl border border-emerald-100">
                    <span class="text-xs font-bold text-emerald-600 uppercase tracking-widest">Order Summary</span>
                    <h3 class="text-xl font-bold text-gray-800 mb-4 mt-1">Your Items</h3>

                    <div class="space-y-3 mb-4">
                        <?php foreach ($cartItems as $item):
                            $cartLn = trim((string) ($item['lineNote'] ?? ''));
                        ?>
                        <div class="flex justify-between text-sm">
                            <div>
                                <p class="font-semibold text-gray-700"><?= htmlspecialchars($item['itemName']) ?></p>
                                <p class="text-xs text-gray-400"><?= htmlspecialchars($item['shopName']) ?> · x<?= $item['quantity'] ?></p>
                                <?php if ($cartLn !== ''): ?>
                                <p class="text-xs text-amber-700 mt-1"><span class="font-semibold">Note:</span> <?= htmlspecialchars($cartLn) ?></p>
                                <?php endif; ?>
                            </div>
                            <p class="font-bold text-emerald-600">₱<?= number_format((float)$item['itemPrice'] * (int)$item['quantity'], 2) ?></p>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <hr class="border-emerald-100 mb-4">

                    <div class="flex justify-between items-center py-2 mb-4">
                        <p class="text-lg font-bold text-gray-800">Total</p>
                        <p class="text-2xl font-black text-emerald-600">₱<?= number_format($total, 2) ?></p>
                    </div>

                    <button type="submit" name="placeOrder"
                            class="w-full bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-4 rounded-xl shadow-lg shadow-emerald-200 transition-all uppercase text-xs tracking-widest">
                        Place Order
                    </button>
                    <a href="student.php" class="block text-center text-sm text-gray-400 mt-3 hover:text-gray-600">← Back to Dashboard</a>
                </div>

            </div>
        </form>
    </div>

<script>
let checkoutMap;
let checkoutMarker;
const postedDeliveryLat = <?= json_encode($_POST['deliveryLat'] ?? '') ?>;
const postedDeliveryLng = <?= json_encode($_POST['deliveryLng'] ?? '') ?>;
function initCheckoutMap() {
    if (!window.L || checkoutMap) return;
    const fallback = [14.5995, 120.9842];
    checkoutMap = L.map('checkoutMap').setView(fallback, 16);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap' }).addTo(checkoutMap);
    checkoutMap.on('click', function (e) {
        setCheckoutLocation(e.latlng.lat, e.latlng.lng);
    });
    if (postedDeliveryLat && postedDeliveryLng && !Number.isNaN(parseFloat(postedDeliveryLat)) && !Number.isNaN(parseFloat(postedDeliveryLng))) {
        setCheckoutLocation(parseFloat(postedDeliveryLat), parseFloat(postedDeliveryLng));
    }
    setTimeout(function () { checkoutMap.invalidateSize(); }, 250);
}
function setCheckoutLocation(lat, lng) {
    document.getElementById('deliveryLat').value = Number(lat).toFixed(8);
    document.getElementById('deliveryLng').value = Number(lng).toFixed(8);
    const help = document.getElementById('checkoutLocationHelp');
    if (help) {
        help.textContent = 'Location pinned. You can drag the marker to adjust it.';
        help.className = 'text-xs text-emerald-600 font-semibold mt-2';
    }
    if (!checkoutMap) return;
    const point = [lat, lng];
    if (!checkoutMarker) {
        checkoutMarker = L.marker(point, { draggable: true }).addTo(checkoutMap);
        checkoutMarker.on('dragend', function () {
            const p = checkoutMarker.getLatLng();
            setCheckoutLocation(p.lat, p.lng);
        });
    } else {
        checkoutMarker.setLatLng(point);
    }
    checkoutMap.setView(point, Math.max(checkoutMap.getZoom(), 16));
}
function checkoutUseCurrentLocation() {
    if (!navigator.geolocation) return alert('Location is not available in this browser.');
    navigator.geolocation.getCurrentPosition(function (pos) {
        setCheckoutLocation(pos.coords.latitude, pos.coords.longitude);
    }, function () {
        alert('Could not get your location. You can tap the map instead.');
    }, { enableHighAccuracy: true, timeout: 10000 });
}
function checkoutClearLocation() {
    document.getElementById('deliveryLat').value = '';
    document.getElementById('deliveryLng').value = '';
    const help = document.getElementById('checkoutLocationHelp');
    if (help) {
        help.textContent = 'Required: tap the map or use your current location to pin where the seller should deliver your order.';
        help.className = 'text-xs text-red-600 font-semibold mt-2';
    }
    if (checkoutMarker && checkoutMap) {
        checkoutMap.removeLayer(checkoutMarker);
        checkoutMarker = null;
    }
}
document.addEventListener('DOMContentLoaded', function () {
    initCheckoutMap();
    const form = document.getElementById('checkoutForm');
    if (form) {
        form.addEventListener('submit', function (event) {
            const lat = parseFloat(document.getElementById('deliveryLat').value);
            const lng = parseFloat(document.getElementById('deliveryLng').value);
            if (!Number.isFinite(lat) || !Number.isFinite(lng)) {
                event.preventDefault();
                checkoutClearLocation();
                document.getElementById('checkoutMap').scrollIntoView({ behavior: 'smooth', block: 'center' });
                alert('Please pin your delivery location on the map before placing your order.');
            }
        });
    }
});
</script>

</body>
</html>
