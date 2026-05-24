<?php
// THIS FILE SHOWS THE USER PROFILE, CREATES POSTS, EDITS POSTS, AND MANAGES PROFILE CONTENT.
require_once __DIR__ . '/session_config.php';
session_start();
require_once __DIR__ . '/db.php';

if (!isset($_SESSION['user'])) {
    header("Location: index.php");
    exit();
}

// CONNECT TO ANIMEALS FOR USER DATA AND ANIMEALS_POSTS FOR SOCIAL POSTS.
$conn = db_connect(DB_NAME_ANIMEALS);
$connPosts = db_connect(DB_NAME_ANIMEALS_POSTS);

// MAKE SURE MAP FIELDS, SHARE COUNTS, AND MULTI-IMAGE POST TABLES EXIST.
require_once __DIR__ . '/schema_bootstrap.php';
animeals_posts_ensure_extensions($connPosts);

// LOAD THE LOGGED-IN USER RECORD; IF IT IS MISSING, FORCE A CLEAN LOGIN.
$stmt = db_query($conn, "SELECT * FROM user_details WHERE userEMAIL = ?", [$_SESSION['email']]);
if ($stmt === false || !($user = db_fetch_assoc($stmt))) {
    session_destroy();
    header('Location: index.php');
    exit();
}

$roleKey = strtolower(trim((string) ($user['userROLE'] ?? 'student')));
$backPage = match ($roleKey) {
    'seller' => 'seller.php',
    'admin' => 'admin.php',
    default => 'student.php',
};

$profilePic = !empty($user['userPROFILEPIC'])
    ? htmlspecialchars($user['userPROFILEPIC'])
    : 'https://cdn-icons-png.flaticon.com/512/149/149071.png';

function profile_format_datetime($value, string $format): string
{
    // FORMAT MYSQL DATETIME VALUES SAFELY WHETHER THEY ARRIVE AS STRINGS OR DATE OBJECTS.
    if ($value instanceof DateTimeInterface) {
        return $value->format($format);
    }
    if (is_string($value) && $value !== '') {
        $ts = strtotime($value);
        if ($ts !== false) {
            return date($format, $ts);
        }
    }
    return date($format);
}

function profile_json_error(string $message): never
{
    // SEND A STANDARD JSON ERROR SHAPE TO THE FRONT END AND STOP THE REQUEST.
    echo json_encode(['success' => false, 'message' => $message]);
    exit();
}

function profile_upload_post_images(string $fieldName, string $prefix): array
{
    // ACCEPT SINGLE OR MULTIPLE IMAGE UPLOADS AND RETURN THE SAVED FILE PATHS.
    if (empty($_FILES[$fieldName]['name'])) {
        return [];
    }

    $fileSet = $_FILES[$fieldName];
    $names = is_array($fileSet['name']) ? $fileSet['name'] : [$fileSet['name']];
    $tmpNames = is_array($fileSet['tmp_name']) ? $fileSet['tmp_name'] : [$fileSet['tmp_name']];
    $errors = is_array($fileSet['error']) ? $fileSet['error'] : [$fileSet['error']];

    $uploadDir = 'uploads/posts/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $paths = [];
    // ONLY ALLOW WEB-FRIENDLY IMAGE EXTENSIONS FOR POST GALLERIES.
    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    foreach ($names as $idx => $name) {
        if ($name === '' || ($errors[$idx] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            continue;
        }
        $ext = strtolower(pathinfo((string) $name, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed, true)) {
            continue;
        }
        $filename = uniqid($prefix, true) . '.' . $ext;
        $target = $uploadDir . $filename;
        if (move_uploaded_file((string) ($tmpNames[$idx] ?? ''), $target)) {
            $paths[] = $target;
        }
    }
    return $paths;
}

function profile_insert_post_images(mysqli $connPosts, int $postID, array $paths, int $startOrder = 0): void
{
    // SAVE EACH EXTRA IMAGE WITH A DISPLAY ORDER SO THE GALLERY STAYS CONSISTENT.
    foreach (array_values($paths) as $idx => $path) {
        db_query($connPosts, "INSERT INTO post_images (postID, imagePath, displayOrder) VALUES (?, ?, ?)", [$postID, $path, $startOrder + $idx]);
    }
}

function profile_get_post_images(mysqli $connPosts, int $postID, ?string $legacyImage = null): array
{
    // LOAD THE NEW GALLERY IMAGES, BUT FALL BACK TO THE OLD SINGLE IMAGE COLUMN IF NEEDED.
    $paths = [];
    $stmt = db_query($connPosts, "SELECT imagePath FROM post_images WHERE postID = ? ORDER BY displayOrder ASC, imageID ASC", [$postID]);
    $rows = $stmt ? db_fetch_all($stmt) : [];
    foreach ($rows as $row) {
        $path = trim((string) ($row['imagePath'] ?? ''));
        if ($path !== '') {
            $paths[] = $path;
        }
    }
    $legacyImage = trim((string) $legacyImage);
    if ($paths === [] && $legacyImage !== '') {
        $paths[] = $legacyImage;
    }
    return $paths;
}

function profile_get_images_for_posts(mysqli $connPosts, array $posts): array
{
    // BATCH LOAD POST IMAGES TO AVOID QUERYING ONCE FOR EVERY POST CARD.
    $postIds = array_values(array_filter(array_map(static fn ($post) => (int) ($post['postID'] ?? 0), $posts)));
    $imagesByPost = [];
    if ($postIds !== []) {
        $inList = implode(',', $postIds);
        $stmt = db_query($connPosts, "SELECT postID, imagePath FROM post_images WHERE postID IN ($inList) ORDER BY postID ASC, displayOrder ASC, imageID ASC");
        $rows = $stmt ? db_fetch_all($stmt) : [];
        foreach ($rows as $row) {
            $pid = (int) ($row['postID'] ?? 0);
            $path = trim((string) ($row['imagePath'] ?? ''));
            if ($pid > 0 && $path !== '') {
                $imagesByPost[$pid][] = $path;
            }
        }
    }
    foreach ($posts as $post) {
        $pid = (int) ($post['postID'] ?? 0);
        $legacy = trim((string) ($post['postIMAGE'] ?? ''));
        if ($pid > 0 && empty($imagesByPost[$pid]) && $legacy !== '') {
            $imagesByPost[$pid] = [$legacy];
        }
    }
    return $imagesByPost;
}

function profile_delete_image_files(array $paths): void
{
    // REMOVE UPLOADED IMAGE FILES WHEN A POST OR POST GALLERY IS DELETED.
    foreach ($paths as $path) {
        $path = trim((string) $path);
        if ($path !== '' && is_file($path)) {
            @unlink($path);
        }
    }
}

/* ============================================================
   AJAX ENDPOINTS — return JSON and exit
   ============================================================ */

/* -- Submit new post -- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submitPost') {
    // CREATE A POST THROUGH AJAX SO THE PROFILE FEED CAN UPDATE WITHOUT RELOADING.
    header('Content-Type: application/json; charset=UTF-8');
    animeals_posts_ensure_extensions($connPosts);

    $content = trim($_POST['postContent'] ?? '');
    $lat = isset($_POST['postLat']) && is_numeric($_POST['postLat']) ? (float) $_POST['postLat'] : null;
    $lng = isset($_POST['postLng']) && is_numeric($_POST['postLng']) ? (float) $_POST['postLng'] : null;
    $address = trim($_POST['postAddress'] ?? '');
    $imagePaths = profile_upload_post_images('postImage', 'post_');
    $imagePath = $imagePaths[0] ?? '';

    if ($content || $imagePaths !== []) {
            $stmt = db_query($connPosts,
                "INSERT INTO posts (userEMAIL, postCONTENT, postIMAGE, postLAT, postLNG, postADDRESS) VALUES (?, ?, ?, ?, ?, ?)",
                [$_SESSION['email'], $content, $imagePath, $lat, $lng, $address]
            );
            if ($stmt === false) {
                profile_json_error(db_last_error($connPosts) ?: 'Could not save post.');
            }

            $newId = (int) $connPosts->insert_id;
            if ($newId <= 0) {
                // Fallback: try selecting the most recent post by this user
                $sel = db_query($connPosts, "SELECT * FROM posts WHERE userEMAIL = ? ORDER BY postDATE DESC LIMIT 1", [$_SESSION['email']]);
            } else {
                profile_insert_post_images($connPosts, $newId, $imagePaths);
                $sel = db_query($connPosts, "SELECT * FROM posts WHERE postID = ?", [$newId]);
            }
            if ($sel === false) profile_json_error(db_last_error($connPosts) ?: 'Post saved, but could not reload it.');
            $row = db_fetch_assoc($sel);
            if (!$row) profile_json_error('Post saved, but no post row was returned.');
            if ($newId <= 0) {
                $newId = (int) ($row['postID'] ?? 0);
                if ($newId > 0) {
                    profile_insert_post_images($connPosts, $newId, $imagePaths);
                }
            }
            $postImages = profile_get_post_images($connPosts, (int) ($row['postID'] ?? 0), $row['postIMAGE'] ?? '');
            echo json_encode([
                'success'     => true,
                'postID'      => $row['postID'],
                'postCONTENT' => $row['postCONTENT'],
                'postIMAGE'   => $row['postIMAGE'],
                'postIMAGES'  => $postImages,
                'postLAT'     => $row['postLAT'],
                'postLNG'     => $row['postLNG'],
                'postADDRESS' => $row['postADDRESS'],
                'postLIKES'   => 0,
                'postDATE'    => profile_format_datetime($row['postDATE'] ?? null, 'F j \a\t g:i A')
            ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Nothing to post.']);
    }
    exit();
}

/* -- Edit post -- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'editPost') {
    $postID = (int) ($_POST['postID'] ?? 0);
    $content = trim((string) ($_POST['postContent'] ?? ''));
    $lat = isset($_POST['postLat']) && is_numeric($_POST['postLat']) ? (float) $_POST['postLat'] : null;
    $lng = isset($_POST['postLng']) && is_numeric($_POST['postLng']) ? (float) $_POST['postLng'] : null;
    $address = trim((string) ($_POST['postAddress'] ?? ''));
    $removeImage = !empty($_POST['removeImage']);

    if ($postID > 0) {
        $curStmt = db_query($connPosts, "SELECT postIMAGE FROM posts WHERE postID = ? AND userEMAIL = ?", [$postID, $_SESSION['email']]);
        $currentPost = $curStmt ? db_fetch_assoc($curStmt) : null;
        if (!$currentPost) {
            echo json_encode(['success' => false, 'message' => 'Post not found.']);
            exit();
        }
        $oldImages = profile_get_post_images($connPosts, $postID, $currentPost['postIMAGE'] ?? '');
        $newImages = profile_upload_post_images('postImage', 'post_edit_');

        if ($removeImage) {
            profile_delete_image_files($oldImages);
            db_query($connPosts, "DELETE FROM post_images WHERE postID = ?", [$postID]);
        }

        if ($newImages !== []) {
            profile_insert_post_images($connPosts, $postID, $newImages, $removeImage ? 0 : count($oldImages));
        }

        $finalImages = array_values(array_merge($removeImage ? [] : $oldImages, $newImages));
        $finalImage = $finalImages[0] ?? '';

        db_query($connPosts,
            "UPDATE posts SET postCONTENT = ?, postIMAGE = ?, postLAT = ?, postLNG = ?, postADDRESS = ? WHERE postID = ? AND userEMAIL = ?",
            [$content, $finalImage, $lat, $lng, $address, $postID, $_SESSION['email']]
        );

        $updStmt = db_query($connPosts, "SELECT postIMAGE, postLAT, postLNG, postADDRESS FROM posts WHERE postID = ?", [$postID]);
        $updatedPost = $updStmt ? db_fetch_assoc($updStmt) : [];
        $updatedImages = profile_get_post_images($connPosts, $postID, $updatedPost['postIMAGE'] ?? '');

        echo json_encode([
            'success' => true,
            'postCONTENT' => $content,
            'postIMAGE' => $updatedPost['postIMAGE'] ?? '',
            'postIMAGES' => $updatedImages,
            'postLAT' => $updatedPost['postLAT'] ?? null,
            'postLNG' => $updatedPost['postLNG'] ?? null,
            'postADDRESS' => $updatedPost['postADDRESS'] ?? ''
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid post ID.']);
    }
    exit();
}

/* -- Delete post -- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'deletePost') {
    $postID = (int) ($_POST['postID'] ?? 0);
    if ($postID > 0) {
        $curStmt = db_query($connPosts, "SELECT postIMAGE FROM posts WHERE postID = ? AND userEMAIL = ?", [$postID, $_SESSION['email']]);
        $currentPost = $curStmt ? db_fetch_assoc($curStmt) : null;
        if ($currentPost) {
            profile_delete_image_files(profile_get_post_images($connPosts, $postID, $currentPost['postIMAGE'] ?? ''));
        }
        db_query($connPosts, "DELETE cl FROM comment_likes cl JOIN comments c ON cl.commentID = c.commentID WHERE c.postID = ?", [$postID]);
        db_query($connPosts, "DELETE FROM comments WHERE postID = ?", [$postID]);
        db_query($connPosts, "DELETE FROM post_likes WHERE postID = ?", [$postID]);
        db_query($connPosts, "DELETE FROM post_images WHERE postID = ?", [$postID]);
        db_query($connPosts, "DELETE FROM posts WHERE postID = ? AND userEMAIL = ?", [$postID, $_SESSION['email']]);
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid post ID.']);
    }
    exit();
}

/* -- Toggle post like -- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggleLike') {
    $postID = (int)$_POST['postID'];
    $check  = db_query($connPosts, "SELECT 1 AS liked FROM post_likes WHERE postID = ? AND userEMAIL = ?", [$postID, $_SESSION['email']]);

    if (db_has_rows($check)) {
        db_query($connPosts, "DELETE FROM post_likes WHERE postID = ? AND userEMAIL = ?", [$postID, $_SESSION['email']]);
        db_query($connPosts, "UPDATE posts SET postLIKES = GREATEST(COALESCE(postLIKES,0) - 1, 0) WHERE postID = ?", [$postID]);
        $liked = false;
    } else {
        db_query($connPosts, "INSERT INTO post_likes (postID, userEMAIL) VALUES (?, ?)", [$postID, $_SESSION['email']]);
        db_query($connPosts, "UPDATE posts SET postLIKES = COALESCE(postLIKES,0) + 1 WHERE postID = ?", [$postID]);
        $liked = true;
    }

    $postRowStmt = db_query($connPosts, "SELECT postLIKES FROM posts WHERE postID = ?", [$postID]);
    $postRow = $postRowStmt ? db_fetch_assoc($postRowStmt) : null;
    echo json_encode(['success' => true, 'liked' => $liked, 'likes' => $postRow['postLIKES'] ?? 0]);
    exit();
}

/* -- Submit new comment -- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submitComment') {
    $postID  = (int)$_POST['postID'];
    $content = trim($_POST['commentContent'] ?? '');

    if ($content) {
        $ins = db_query($connPosts, "INSERT INTO comments (postID, userEMAIL, commentCONTENT, commentLIKES) VALUES (?, ?, ?, 0)", [$postID, $_SESSION['email'], $content]);
        if ($ins === false) {
            echo json_encode(['success' => false]);
            exit();
        }
        $newId = (int) $connPosts->insert_id;
        $sel = $newId > 0 ? db_query($connPosts, "SELECT * FROM comments WHERE commentID = ?", [$newId]) : db_query($connPosts, "SELECT * FROM comments WHERE postID = ? AND userEMAIL = ? ORDER BY commentDATE DESC LIMIT 1", [$postID, $_SESSION['email']]);
        $newComment = $sel ? db_fetch_assoc($sel) : null;
        echo json_encode([
            'success'        => true,
            'commentID'      => $newComment['commentID'] ?? null,
            'commentCONTENT' => $newComment['commentCONTENT'] ?? $content,
            'commentLIKES'   => 0,
            'commentDATE'    => isset($newComment['commentDATE']) ? profile_format_datetime($newComment['commentDATE'], 'M j \a\t g:i A') : '',
            'userNAME'       => $user['userNAME'],
            'userPROFILEPIC' => $profilePic
        ]);
    } else {
        echo json_encode(['success' => false]);
    }
    exit();
}

/* -- Toggle comment like -- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggleCommentLike') {
    $commentID = (int)$_POST['commentID'];
    $check     = db_query($connPosts, "SELECT commentLikeID FROM comment_likes WHERE commentID = ? AND userEMAIL = ?", [$commentID, $_SESSION['email']]);

    if (db_has_rows($check)) {
        db_query($connPosts, "DELETE FROM comment_likes WHERE commentID = ? AND userEMAIL = ?", [$commentID, $_SESSION['email']]);
        db_query($connPosts, "UPDATE comments SET commentLIKES = GREATEST(COALESCE(commentLIKES,0) - 1, 0) WHERE commentID = ?", [$commentID]);
        $liked = false;
    } else {
        db_query($connPosts, "INSERT INTO comment_likes (commentID, userEMAIL) VALUES (?, ?)", [$commentID, $_SESSION['email']]);
        db_query($connPosts, "UPDATE comments SET commentLIKES = COALESCE(commentLIKES,0) + 1 WHERE commentID = ?", [$commentID]);
        $liked = true;
    }

    $commentRowStmt = db_query($connPosts, "SELECT commentLIKES FROM comments WHERE commentID = ?", [$commentID]);
    $commentRow = $commentRowStmt ? db_fetch_assoc($commentRowStmt) : null;
    echo json_encode(['success' => true, 'liked' => $liked, 'likes' => $commentRow['commentLIKES'] ?? 0]);
    exit();
}

/* -- Increment post share count (optional column postSHARES on POSTS) -- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'incrementShare') {
    $postID = (int) ($_POST['postID'] ?? 0);
    if ($postID <= 0 || $connPosts === false) {
        echo json_encode(['success' => true, 'shares' => null, 'clipboard' => true]);
        exit();
    }
    $up = db_query($connPosts, 'UPDATE posts SET postSHARES = COALESCE(postSHARES,0) + 1 WHERE postID = ?', [$postID]);
    if ($up === false) {
        echo json_encode(['success' => true, 'shares' => null, 'clipboard' => true]);
        exit();
    }
    $cntStmt = db_query($connPosts, 'SELECT postSHARES FROM posts WHERE postID = ?', [$postID]);
    $row = $cntStmt ? db_fetch_assoc($cntStmt) : null;
    echo json_encode([
        'success' => true,
        'shares' => $row !== null ? (int) ($row['postSHARES'] ?? 0) : null,
    ]);
    exit();
}

/* ============================================================
   LOAD ALL POSTS (initial page load)
   ============================================================ */
// Load posts for this user
$postStmt = db_query($connPosts, "SELECT * FROM posts WHERE userEMAIL = ? ORDER BY postDATE DESC", [$_SESSION['email']]);
$posts = [];
$rows = $postStmt ? db_fetch_all($postStmt) : [];
$imagesByPost = profile_get_images_for_posts($connPosts, $rows);
foreach ($rows as $row) {
    $row['postIMAGES'] = $imagesByPost[(int) ($row['postID'] ?? 0)] ?? [];
    $likeCheck = db_query($connPosts, "SELECT 1 AS liked FROM post_likes WHERE postID = ? AND userEMAIL = ?", [$row['postID'], $_SESSION['email']]);
    $row['userLiked'] = db_has_rows($likeCheck);

    $commentStmt = db_query($connPosts,
        "SELECT c.*, u.userNAME, u.userPROFILEPIC
         FROM comments c
         JOIN animeals.user_details u ON c.userEMAIL = u.userEMAIL
         WHERE c.postID = ?
         ORDER BY c.commentDATE ASC",
        [$row['postID']]
    );

    $row['comments'] = [];
    $comments = $commentStmt ? db_fetch_all($commentStmt) : [];
    foreach ($comments as $comment) {
        $cLikeCheck = db_query($connPosts, "SELECT commentLikeID FROM comment_likes WHERE commentID = ? AND userEMAIL = ?", [$comment['commentID'], $_SESSION['email']]);
        $comment['userLikedComment'] = db_has_rows($cLikeCheck);
        $row['comments'][] = $comment;
    }

    $posts[] = $row;
}

$postCount = count($posts);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ANIMEALS | Profile</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Poppins', sans-serif; }
        body { background: #f3f7f5; }
        .container { max-width: 900px; margin: auto; padding: 30px; }

        /* ── PROFILE HEADER ── */
        .profile-header {
            background: white;
            border-radius: 30px;
            overflow: hidden;
            box-shadow: 0 10px 25px rgba(0,0,0,0.08);
            margin-bottom: 25px;
            position: relative;
        }
        .profile-banner {
            height: 140px;
            background: #fff;
            display: flex; align-items: center; justify-content: center;
            color: #0f7b55; font-weight: 700; font-size: 22px;
        }
        .profile-banner img {
            width: min(72%, 420px);
            height: 128px;
            object-fit: contain;
            border-radius: 0;
            background: transparent;
            padding: 0;
            box-shadow: none;
        }
        .back-btn {
            position: absolute; left: 20px; top: 20px;
            color: white; font-size: 22px; cursor: pointer; z-index: 10;
        }
        .profile-picture {
            position: absolute; left: 50%; top: 118px; transform: translateX(-50%);
        }
        .profile-picture img {
            width: 130px; height: 130px; border-radius: 50%;
            border: 6px solid white; object-fit: cover;
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }
        .edit-photo {
            position: absolute; right: 5px; bottom: 5px;
            background: #1dbf73; width: 32px; height: 32px;
            border-radius: 50%; display: flex; align-items: center;
            justify-content: center; color: white; cursor: pointer;
        }
        .profile-info { padding-top: 118px; padding-bottom: 35px; text-align: center; }
        .profile-info h2   { font-size: 22px; }
        .profile-info span { font-size: 14px; color: #666; }
        .stats {
            display: flex; justify-content: center; gap: 40px;
            margin-top: 20px; font-size: 14px; color: #444;
        }
        .stats div { display: flex; align-items: center; gap: 6px; }
        .edit-btn {
            position: absolute; right: 30px; bottom: 25px;
            padding: 8px 18px; border-radius: 20px; border: none;
            background: #1dbf73; color: white; cursor: pointer; font-weight: 500;
        }

        /* ── POST BOX ── */
        .post-box {
            background: white; border-radius: 20px;
            padding: 15px 20px; display: flex; align-items: center; gap: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05); margin-bottom: 10px;
        }
        .post-box input {
            flex: 1; border: none; outline: none; font-size: 14px;
            background: #f1f4f3; padding: 10px 15px; border-radius: 20px;
        }
        .post-icons { display: flex; gap: 12px; font-size: 20px; color: #555; }

        /* ── POSTS LIST ── */
        .posts {
            background: white; border-radius: 25px; padding: 20px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
        }
        .post { margin-bottom: 25px; border-bottom: 1px solid #f0f0f0; padding-bottom: 15px; }
        .post-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px; }
        .post-user-info { display: flex; align-items: center; gap: 10px; }
        .post-user-info img { width: 45px; height: 45px; border-radius: 50%; object-fit: cover; }
        .post-header-actions { position: relative; }
        .menu-toggle {
            background: transparent;
            border: none;
            color: #555;
            font-size: 1.1rem;
            padding: 8px;
            cursor: pointer;
            border-radius: 50%;
        }
        .menu-toggle:hover { background: rgba(0,0,0,0.04); }
        .post-menu {
            position: absolute;
            right: 0;
            top: 36px;
            background: white;
            border-radius: 14px;
            box-shadow: 0 14px 30px rgba(0,0,0,0.12);
            display: none;
            min-width: 140px;
            z-index: 20;
            overflow: hidden;
        }
        .post-menu.open { display: block; }
        .post-menu button {
            width: 100%;
            text-align: left;
            background: none;
            border: none;
            padding: 10px 14px;
            font-size: 14px;
            color: #333;
            cursor: pointer;
        }
        .post-menu button:hover { background: #f7f8fa; }
        .user-details b     { display: block; font-size: 15px; line-height: 1.2; }
        .user-details small { color: #777; font-size: 12px; display: flex; align-items: center; gap: 4px; }
        .post-content p   { font-size: 14px; margin-bottom: 10px; }
        .post-content img { cursor: zoom-in; }
        .post-content > img { width: 100%; border-radius: 15px; margin-top: 5px; }
        .post-image-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
            gap: 8px;
            margin-top: 8px;
        }
        .post-image-grid img {
            width: 100%;
            aspect-ratio: 1 / 1;
            border-radius: 15px;
            object-fit: cover;
        }
        .post-meta { font-size: 12px; color: #999; margin-top: 10px; margin-bottom: 6px; display: flex; gap: 15px; }

        /* ── ACTION BUTTONS ── */
        .post-actions { margin-top: 12px; display: flex; gap: 20px; }
        .action-btn {
            background: none; border: none; cursor: pointer;
            font-size: 15px; color: #555; display: flex;
            align-items: center; gap: 5px; padding: 0; font-family: 'Poppins', sans-serif;
        }
        .action-btn.liked { color: #e74c3c; }
        .action-btn:hover { color: #1dbf73; }
        .action-btn.liked:hover { color: #c0392b; }

        /* ── COMMENTS ── */
        .comment-section { margin-top: 12px; }
        .comment-item { display: flex; gap: 10px; margin-bottom: 10px; align-items: flex-start; }
        .comment-item img { width: 32px; height: 32px; border-radius: 50%; object-fit: cover; flex-shrink: 0; }
        .comment-bubble { background: #f1f4f3; padding: 8px 12px; border-radius: 15px; flex: 1; }
        .comment-bubble b     { font-size: 13px; display: block; }
        .comment-bubble p     { font-size: 13px; color: #444; margin: 2px 0; }
        .comment-bubble small { color: #999; font-size: 11px; }

        /* Comment action row (like + reply) */
        .comment-actions { display: flex; gap: 15px; margin-top: 4px; }
        .comment-like-btn, .comment-reply-btn {
            background: none; border: none; cursor: pointer;
            font-size: 12px; color: #999; display: flex;
            align-items: center; gap: 4px; padding: 0;
            font-family: 'Poppins', sans-serif;
        }
        .comment-like-btn.liked { color: #e74c3c; }
        .comment-like-btn:hover { color: #e74c3c; }
        .comment-reply-btn:hover { color: #1dbf73; }

        /* Reply box (hidden by default) */
        .reply-form {
            display: none;
            gap: 8px;
            align-items: center;
            margin-top: 8px;
            margin-left: 42px;
        }
        .reply-form input {
            flex: 1; border: none; outline: none;
            background: #f1f4f3; padding: 7px 12px;
            border-radius: 20px; font-size: 12px;
        }
        .reply-form button {
            background: #1dbf73; color: white; border: none;
            padding: 6px 12px; border-radius: 20px;
            font-weight: 600; cursor: pointer; font-size: 12px;
        }

        /* Show more comments button */
        .show-more-btn {
            background: none; border: none; color: #1dbf73;
            font-size: 13px; font-weight: 600; cursor: pointer;
            padding: 5px 0; display: block; margin: 5px 0 10px;
            font-family: 'Poppins', sans-serif;
        }
        .show-more-btn:hover { text-decoration: underline; }

        /* Comments hidden beyond limit */
        .comment-hidden { display: none; }

        /* Comment input form */
        .comment-form {
            display: flex; gap: 10px; align-items: center; margin-top: 10px;
        }
        .comment-form input {
            flex: 1; border: none; outline: none; background: #f1f4f3;
            padding: 8px 14px; border-radius: 20px; font-size: 13px;
        }
        .comment-form button {
            background: #1dbf73; color: white; border: none;
            padding: 7px 15px; border-radius: 20px;
            font-weight: 600; cursor: pointer; font-size: 13px;
        }

        /* Image preview box */
        #imagePreviewBox { display: none; margin-bottom: 15px; position: relative; }
        #imagePreviewGrid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(110px, 1fr));
            gap: 8px;
        }
        #imagePreviewGrid img {
            width: 100%;
            aspect-ratio: 1 / 1;
            object-fit: cover;
            border-radius: 15px;
        }
        .clear-preview   {
            position: absolute; top: 8px; right: 8px;
            background: rgba(0,0,0,0.5); color: white;
            border-radius: 50%; width: 26px; height: 26px;
            display: flex; align-items: center; justify-content: center;
            cursor: pointer; font-size: 16px;
        }

        .photo-lightbox {
            display: none; position: fixed; inset: 0; z-index: 9999;
            background: rgba(0,0,0,0.88); align-items: center; justify-content: center;
            padding: 24px; cursor: zoom-out;
        }
        .photo-lightbox.open { display: flex; }
        .photo-lightbox img {
            max-width: min(92vw, 720px); max-height: 88vh; width: auto; height: auto;
            border-radius: 16px; object-fit: contain; box-shadow: 0 20px 60px rgba(0,0,0,0.5);
            cursor: default;
        }
        .profile-picture img.profile-avatar-main { cursor: pointer; }
        .post-image-viewer {
            position: fixed;
            inset: 0;
            display: none;
            z-index: 10000;
            background: rgba(0,0,0,0.88);
            overflow: auto;
            padding: 72px 18px 32px;
        }
        .post-image-viewer.open { display: flex; align-items: flex-start; justify-content: center; }
        .post-image-viewer img {
            width: auto;
            max-width: min(96vw, 1100px);
            height: auto;
            border-radius: 16px;
            box-shadow: 0 24px 70px rgba(0,0,0,0.45);
            cursor: default;
        }
        .post-image-viewer-close {
            position: fixed;
            top: 18px;
            right: 18px;
            width: 42px;
            height: 42px;
            border: none;
            border-radius: 50%;
            background: rgba(255,255,255,0.95);
            color: #111;
            font-size: 28px;
            line-height: 1;
            cursor: pointer;
            z-index: 10001;
        }
        .edit-modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.4);
            backdrop-filter: blur(2px);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 9990;
            padding: 20px;
        }
        .edit-modal-overlay.open {
            display: flex;
        }
        .edit-modal {
            width: min(640px, 100%);
            background: white;
            border-radius: 24px;
            box-shadow: 0 30px 70px rgba(0,0,0,0.18);
            padding: 22px;
            max-height: 90vh;
            overflow-y: auto;
        }
        .edit-modal h2 {
            margin-bottom: 14px;
            font-size: 20px;
            color: #112;
        }
        .edit-modal textarea,
        .edit-modal input[type="text"],
        .edit-modal input[type="file"] {
            width: 100%;
            font-family: 'Poppins', sans-serif;
            font-size: 14px;
        }
        .edit-modal textarea {
            min-height: 120px;
            resize: vertical;
            padding: 12px 14px;
            border-radius: 16px;
            border: 1px solid #e4e7ec;
            margin-bottom: 16px;
        }
        .edit-image-row,
        .edit-location-row {
            display: flex;
            gap: 14px;
            flex-wrap: wrap;
            margin-bottom: 14px;
            align-items: center;
        }
        .edit-file-label {
            display: block;
            margin-bottom: 6px;
            font-weight: 600;
            color: #0f7b55;
        }
        .edit-image-preview {
            width: 100%;
            display: grid;
            gap: 8px;
            background: #f7fdf7;
            padding: 12px;
            border-radius: 18px;
            border: 1px solid #d7f0de;
        }
        .edit-image-preview img {
            width: 100%;
            border-radius: 16px;
            object-fit: cover;
            aspect-ratio: 1 / 1;
        }
        .edit-image-preview label {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #444;
            font-size: 13px;
            cursor: pointer;
        }
        .edit-location-row input {
            flex: 1;
            padding: 12px 14px;
            border-radius: 16px;
            border: 1px solid #e4e7ec;
            background: #fbfcfd;
            color: #222;
        }
        .edit-location-row button {
            background: #0f7b55;
            color: white;
            border: none;
            border-radius: 16px;
            padding: 10px 14px;
            cursor: pointer;
            font-weight: 600;
            flex-shrink: 0;
        }
        .confirm-modal {
            background: #fffdf9;
            border: 1px solid #f7e1d6;
        }
        .confirm-modal h2 {
            color: #b43524;
        }
        .confirm-modal p {
            color: #4a4a4a;
        }
        .edit-modal .btn-save.danger {
            background: #e74c3c;
            color: white;
        }
        .edit-modal .edit-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 18px;
        }
        .edit-modal .btn-save,
        .edit-modal .btn-cancel {
            padding: 12px 18px;
            border: none;
            border-radius: 16px;
            cursor: pointer;
            font-weight: 700;
        }
        .edit-modal .btn-cancel { background: #f4f5f7; color: #333; }
        .edit-modal .btn-save { background: #1dbf73; color: white; }
        .edit-location-map {
            display: none;
            width: 100%;
            min-height: 240px;
            border-radius: 16px;
            overflow: hidden;
            margin-top: 12px;
        }
    </style>
</head>
<body>

<div id="profilePhotoLightbox" class="photo-lightbox" onclick="profileClosePhotoLightbox(event)" aria-hidden="true">
    <img id="profilePhotoLightboxImg" src="" alt="Profile enlarged" onclick="event.stopPropagation()">
</div>

<div id="profilePostImageViewer" class="post-image-viewer" onclick="profileClosePostImageViewer(event)" aria-hidden="true">
    <button type="button" class="post-image-viewer-close" onclick="profileClosePostImageViewer(event)" aria-label="Close">&times;</button>
    <img id="profilePostImageViewerImg" src="" alt="Post preview" onclick="event.stopPropagation()">
</div>

<div class="container">

    <!-- ── PROFILE HEADER ── -->
    <div class="profile-header">
        <div class="back-btn" onclick="location.href='<?= $backPage ?>'">
            <i class="bi bi-arrow-left"></i>
        </div>
        <div class="profile-banner"><img src="logo.png?v=transparent" alt="ANIMEALS Logo"></div>
        <div class="profile-picture">
            <img class="profile-avatar-main" src="<?= $profilePic ?>" alt="Profile" onclick="profileOpenPhotoLightbox(this.src)">
            <div class="edit-photo"><i class="bi bi-pencil"></i></div>
        </div>
        <div class="profile-info">
            <h2><?= htmlspecialchars($user['userNAME']) ?></h2>
            <span><?= htmlspecialchars($user['userSTUDENTNUM'] ?? $user['userEMAIL']) ?></span>
            <div class="stats">
                <div><i class="bi bi-geo"></i> <?= htmlspecialchars($user['userCOLLEGE'] ?? 'N/A') ?></div>
                <div><i class="bi bi-basket"></i> Total Orders: 0</div>
                <div><i class="bi bi-file-post"></i>
                    <span id="postCountStat"><?= $postCount ?></span>
                    <?= $postCount == 1 ? 'Post' : 'Posts' ?>
                </div>
            </div>
        </div>
        <button class="edit-btn">Edit</button>
    </div>

    <!-- ── CREATE POST ── -->
    <div class="post-box" id="createPostBox">
        <img src="<?= $profilePic ?>" alt="Profile" style="width:40px; height:40px; border-radius:50%; object-fit:cover; flex-shrink:0;">
        <input type="text" id="postContentInput" placeholder="What's on your mind">
        <input type="text" id="postLocationInput" placeholder="Add location (optional)" style="flex: 0.5; margin-left: 10px;">
        <div class="post-icons">
            <button type="button" onclick="toggleMap()" style="background:#1dbf73; color:white; border:none; padding:8px 12px; border-radius:20px; font-weight:600; cursor:pointer; font-size:12px; white-space:nowrap;">Choose on map</button>
            <label style="cursor:pointer;" title="Upload image">
                <i class="bi bi-image"></i>
                <input type="file" id="postImageInput" accept="image/*" multiple hidden onchange="previewPostImage(event)">
            </label>
        </div>
        <button type="button" id="submitPostBtn" onclick="submitPost()" style="background:#1dbf73; color:white; border:none; padding:8px 18px; border-radius:20px; font-weight:600; cursor:pointer; white-space:nowrap;">
            Post
        </button>
    </div>
    <div id="imagePreviewBox">
        <div id="imagePreviewGrid"></div>
        <span class="clear-preview" onclick="clearImagePreview()">&times;</span>
    </div>
    <div id="locationMap" style="display:none; height:300px; width:100%; margin-top:10px; border-radius:15px; overflow:hidden;"></div>

    <div id="editModalOverlay" class="edit-modal-overlay" onclick="closeEditModal(event)">
        <div class="edit-modal" onclick="event.stopPropagation()">
            <h2>Edit post</h2>
            <textarea id="editPostContent" placeholder="Update your post..."></textarea>
            <div class="edit-image-row">
                <div style="flex:1; min-width:220px;">
                    <label class="edit-file-label" for="editNewImageInput">Add images</label>
                    <input type="file" id="editNewImageInput" accept="image/*" multiple onchange="previewEditImage(event)">
                </div>
                <div id="editImageWrapper" class="edit-image-preview" style="display:none;">
                    <div id="editImagePreviewGrid" class="post-image-grid"></div>
                    <label><input type="checkbox" id="editRemoveImage"> Remove existing images before saving</label>
                </div>
            </div>
            <div class="edit-location-row">
                <input type="text" id="editPostLocationInput" placeholder="Edit location (optional)">
                <button type="button" onclick="toggleEditMap()">Choose again on map</button>
                <button type="button" onclick="clearEditLocation()" style="background:#ff6b6b;">Clear location</button>
            </div>
            <div id="editLocationMap" class="edit-location-map"></div>
            <div class="edit-actions">
                <button type="button" class="btn-cancel" onclick="closeEditModal()">Cancel</button>
                <button type="button" class="btn-save" onclick="saveEditPost()">Save changes</button>
            </div>
        </div>
    </div>

    <div id="deleteModalOverlay" class="edit-modal-overlay" onclick="closeDeleteModal(event)">
        <div class="edit-modal confirm-modal" onclick="event.stopPropagation()">
            <h2>Delete post</h2>
            <p style="color:#555; line-height:1.6; margin-top:12px;">Are you sure you want to delete this post? This action cannot be undone.</p>
            <div class="edit-actions" style="margin-top:24px;">
                <button type="button" class="btn-cancel" onclick="closeDeleteModal()">Cancel</button>
                <button type="button" class="btn-save danger" onclick="confirmDeletePost()">Delete</button>
            </div>
        </div>
    </div>

    <!-- ── POSTS LIST ── -->
    <div class="posts">
        <h3 style="margin-bottom:15px;">Posts</h3>
        <div id="postsContainer">

            <?php if (empty($posts)): ?>
                <p id="noPostsMsg" style="color:#999; font-size:14px; text-align:center; padding:20px 0;">No posts yet. Share something!</p>
            <?php endif; ?>

            <?php foreach ($posts as $post): ?>
            <?php $postImages = array_values($post['postIMAGES'] ?? []); ?>
            <div class="post" id="post-<?= $post['postID'] ?>"
                 data-postcontent="<?= htmlspecialchars($post['postCONTENT'] ?? '') ?>"
                 data-postimage="<?= htmlspecialchars($post['postIMAGE'] ?? '') ?>"
                 data-postimages="<?= htmlspecialchars(json_encode($postImages, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP), ENT_QUOTES, 'UTF-8') ?>"
                 data-postlat="<?= htmlspecialchars((string) ($post['postLAT'] ?? '')) ?>"
                 data-postlng="<?= htmlspecialchars((string) ($post['postLNG'] ?? '')) ?>"
                 data-postaddress="<?= htmlspecialchars($post['postADDRESS'] ?? '') ?>">

                <div class="post-header">
                    <div class="post-user-info">
                        <img src="<?= $profilePic ?>" alt="Profile">
                        <div class="user-details">
                            <b><?= htmlspecialchars($user['userNAME']) ?></b>
                            <small><?= profile_format_datetime($post['postDATE'] ?? null, 'F j \a\t g:i A') ?> · <i class="bi bi-lock-fill"></i></small>
                        </div>
                    </div>
                    <div class="post-header-actions">
                        <button class="menu-toggle" type="button" onclick="togglePostMenu(<?= $post['postID'] ?>)" aria-label="Post options">
                            <i class="bi bi-three-dots"></i>
                        </button>
                        <div class="post-menu" id="postMenu-<?= $post['postID'] ?>">
                            <button type="button" onclick="editPost(<?= $post['postID'] ?>)">Edit post</button>
                            <button type="button" onclick="deletePost(<?= $post['postID'] ?>)">Delete post</button>
                        </div>
                    </div>
                </div>

                <div class="post-content">
                    <?php if ($post['postCONTENT']): ?>
                        <p><?= htmlspecialchars($post['postCONTENT']) ?></p>
                    <?php endif; ?>
                    <?php if ($postImages !== []): ?>
                        <div class="post-image-grid">
                            <?php foreach ($postImages as $imagePath): ?>
                                <img src="<?= htmlspecialchars((string) $imagePath) ?>" alt="Post image">
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($post['postLAT']) && !empty($post['postLNG'])): ?>
                        <div style="margin-top: 10px;">
                            <iframe src="https://www.google.com/maps/embed/v1/place?key=AIzaSyACss1f2TEk4tUgZXWtjJi-pxkL9dLhDqw&q=<?= $post['postLAT'] ?>,<?= $post['postLNG'] ?>" 
                                    style="width:100%; height:180px; border-radius:10px; border:0;" allowfullscreen></iframe>
                            <?php if (!empty($post['postADDRESS'])): ?>
                                <p style="font-size: 12px; color: #666; margin-top: 5px; text-align: center;">
                                    <i class="bi bi-geo-alt-fill"></i> <?= htmlspecialchars($post['postADDRESS']) ?>
                                </p>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="post-meta">
                    <span id="likeCount-<?= $post['postID'] ?>"><?= $post['postLIKES'] ?> <?= $post['postLIKES'] == 1 ? 'like' : 'likes' ?></span>
                    <span id="commentCount-<?= $post['postID'] ?>"><?= count($post['comments']) ?> <?= count($post['comments']) == 1 ? 'comment' : 'comments' ?></span>
                    <span id="shareCount-<?= $post['postID'] ?>"><?= (int) ($post['postSHARES'] ?? 0) ?> shares</span>
                </div>

                <div class="post-actions">
                    <!-- Like post -->
                    <button class="action-btn <?= $post['userLiked'] ? 'liked' : '' ?>"
                            id="likeBtn-<?= $post['postID'] ?>"
                            onclick="toggleLike(<?= $post['postID'] ?>)">
                        <i class="bi <?= $post['userLiked'] ? 'bi-heart-fill' : 'bi-heart' ?>"></i> Like
                    </button>

                    <!-- Focus comment input -->
                    <button class="action-btn" onclick="focusComment(<?= $post['postID'] ?>)">
                        <i class="bi bi-chat"></i> Comment
                    </button>

                    <button type="button" class="action-btn" onclick="profileSharePost(<?= (int) $post['postID'] ?>)">
                        <i class="bi bi-share"></i> Share
                    </button>
                </div>

                <!-- ── COMMENTS SECTION ── -->
                <div class="comment-section" id="comments-<?= $post['postID'] ?>">

                    <div id="commentList-<?= $post['postID'] ?>">
                        <?php
                        $visibleLimit = 3;
                        foreach ($post['comments'] as $i => $comment):
                            $hidden = $i >= $visibleLimit ? 'comment-hidden' : '';
                        ?>
                        <div class="comment-item <?= $hidden ?>" id="comment-<?= $comment['commentID'] ?>">
                            <img src="<?= !empty($comment['userPROFILEPIC']) ? htmlspecialchars($comment['userPROFILEPIC']) : 'https://cdn-icons-png.flaticon.com/512/149/149071.png' ?>" alt="Commenter">
                            <div style="flex:1;">
                                <div class="comment-bubble">
                                    <b><?= htmlspecialchars($comment['userNAME']) ?></b>
                                    <p><?= htmlspecialchars($comment['commentCONTENT']) ?></p>
                                    <small><?= profile_format_datetime($comment['commentDATE'] ?? null, 'M j \a\t g:i A') ?></small>
                                </div>
                                <div class="comment-actions">
                                    <!-- Like comment -->
                                    <button class="comment-like-btn <?= $comment['userLikedComment'] ? 'liked' : '' ?>"
                                            id="cLikeBtn-<?= $comment['commentID'] ?>"
                                            onclick="toggleCommentLike(<?= $comment['commentID'] ?>)">
                                        <i class="bi <?= $comment['userLikedComment'] ? 'bi-heart-fill' : 'bi-heart' ?>"></i>
                                        <span id="cLikeCount-<?= $comment['commentID'] ?>"><?= $comment['commentLIKES'] ?></span>
                                    </button>
                                    <!-- Reply button -->
                                    <button class="comment-reply-btn" onclick="toggleReply(<?= $comment['commentID'] ?>, <?= $post['postID'] ?>)">
                                        <i class="bi bi-reply"></i> Reply
                                    </button>
                                </div>
                                <!-- Reply form -->
                                <div class="reply-form" id="replyForm-<?= $comment['commentID'] ?>">
                                    <img src="<?= $profilePic ?>" style="width:26px; height:26px; border-radius:50%; object-fit:cover; flex-shrink:0;">
                                    <input type="text" placeholder="Write a reply..." id="replyInput-<?= $comment['commentID'] ?>">
                                    <button onclick="submitReply(<?= $comment['commentID'] ?>, <?= $post['postID'] ?>)">Reply</button>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Show more / less button -->
                    <?php if (count($post['comments']) > $visibleLimit): ?>
                    <button class="show-more-btn" id="showMoreBtn-<?= $post['postID'] ?>"
                            onclick="toggleShowMore(<?= $post['postID'] ?>, <?= count($post['comments']) ?>)">
                        Show <?= count($post['comments']) - $visibleLimit ?> more comments
                    </button>
                    <?php endif; ?>

                    <!-- Add comment -->
                    <div class="comment-form">
                        <img src="<?= $profilePic ?>" alt="Profile" style="width:32px; height:32px; border-radius:50%; object-fit:cover; flex-shrink:0;">
                        <input type="text" id="commentInput-<?= $post['postID'] ?>" placeholder="Write a comment..." onkeydown="if(event.key==='Enter') submitComment(<?= $post['postID'] ?>)">
                        <button onclick="submitComment(<?= $post['postID'] ?>)">Send</button>
                    </div>

                </div><!-- end .comment-section -->

            </div><!-- end .post -->
            <?php endforeach; ?>

        </div><!-- end #postsContainer -->
    </div><!-- end .posts -->

</div><!-- end .container -->

<script>
    // Current user info passed to JS
    const ME = {
        name: <?= json_encode($user['userNAME']) ?>,
        pic:  <?= json_encode($profilePic) ?>
    };

    const COMMENTS_LIMIT = 3;

    function profileOpenPostImageViewer(src) {
        if (!src) return;
        const viewer = document.getElementById('profilePostImageViewer');
        const img = document.getElementById('profilePostImageViewerImg');
        img.src = src;
        viewer.classList.add('open');
        viewer.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
    }

    function profileClosePostImageViewer(event) {
        if (event) event.stopPropagation();
        const viewer = document.getElementById('profilePostImageViewer');
        const img = document.getElementById('profilePostImageViewerImg');
        viewer.classList.remove('open');
        viewer.setAttribute('aria-hidden', 'true');
        img.src = '';
        document.body.style.overflow = '';
    }

    document.addEventListener('click', function (event) {
        const img = event.target.closest('.post-content img');
        if (!img) return;
        event.preventDefault();
        profileOpenPostImageViewer(img.currentSrc || img.src);
    });

    /* ── IMAGE PREVIEW ── */
    function previewPostImage(e) {
        const files = Array.from(e.target.files || []);
        if (!files.length) return clearImagePreview();
        const grid = document.getElementById('imagePreviewGrid');
        grid.innerHTML = files.map(file => `<img src="${URL.createObjectURL(file)}" alt="Selected image">`).join('');
        document.getElementById('imagePreviewBox').style.display = 'block';
    }

    function clearImagePreview() {
        document.getElementById('postImageInput').value = '';
        document.getElementById('imagePreviewGrid').innerHTML = '';
        document.getElementById('imagePreviewBox').style.display = 'none';
    }

    /* ── SUBMIT POST ── */
    async function submitPost() {
        const content = document.getElementById('postContentInput').value.trim();
        const fileInput = document.getElementById('postImageInput');
        if (!content && (!fileInput.files || fileInput.files.length === 0)) return alert('Please write something or upload an image.');
        const submitButton = document.getElementById('submitPostBtn');
        if (submitButton && submitButton.disabled) return;
        if (submitButton) {
            submitButton.disabled = true;
            submitButton.textContent = 'POSTING...';
            submitButton.style.opacity = '0.75';
            submitButton.style.cursor = 'not-allowed';
        }

        const fd = new FormData();
        fd.append('action', 'submitPost');
        fd.append('postContent', content);
        Array.from(fileInput.files || []).forEach(file => fd.append('postImage[]', file));
        if (postLat !== null) fd.append('postLat', postLat);
        if (postLng !== null) fd.append('postLng', postLng);
        if (postAddress) fd.append('postAddress', postAddress);

        let data;
        try {
            const res = await fetch('profile.php', { method: 'POST', body: fd });
            const text = await res.text();
            data = JSON.parse(text);
        } catch (err) {
            alert('Could not publish the post. Please reload and try again.');
        } finally {
            if (submitButton) {
                submitButton.disabled = false;
                submitButton.textContent = 'Post';
                submitButton.style.opacity = '';
                submitButton.style.cursor = 'pointer';
            }
        }

        if (!data) return;
        if (!data.success) {
            alert(data.message || 'Could not publish the post.');
            return;
        }

        // Clear inputs
        document.getElementById('postContentInput').value = '';
        document.getElementById('postLocationInput').value = '';
        clearImagePreview();
        postLat = null;
        postLng = null;
        postAddress = '';
        if (marker) marker.setVisible(false);

        // Remove "no posts" message if present
        const noMsg = document.getElementById('noPostsMsg');
        if (noMsg) noMsg.remove();

        // Prepend new post to the top
        const container = document.getElementById('postsContainer');
        container.insertAdjacentHTML('afterbegin', buildPostHTML(data));

        // Update post count stat
        const stat = document.getElementById('postCountStat');
        stat.textContent = parseInt(stat.textContent) + 1;
    }

    /* ── BUILD POST HTML (for newly created posts) ── */
    function buildPostHTML(data) {
        return `
        <div class="post" id="post-${data.postID}"
             data-postcontent="${escapeHtml(data.postCONTENT || '')}"
             data-postimage="${escapeHtml(data.postIMAGE || '')}"
             data-postimages="${escapeHtml(JSON.stringify(data.postIMAGES || []))}"
             data-postlat="${data.postLAT ?? ''}"
             data-postlng="${data.postLNG ?? ''}"
             data-postaddress="${escapeHtml(data.postADDRESS || '')}">
            <div class="post-header">
                <div class="post-user-info">
                    <img src="${ME.pic}" alt="Profile">
                    <div class="user-details">
                        <b>${ME.name}</b>
                        <small>${data.postDATE} · <i class="bi bi-lock-fill"></i></small>
                    </div>
                </div>
                <div class="post-header-actions">
                    <button class="menu-toggle" type="button" onclick="togglePostMenu(${data.postID})" aria-label="Post options">
                        <i class="bi bi-three-dots"></i>
                    </button>
                    <div class="post-menu" id="postMenu-${data.postID}">
                        <button type="button" onclick="editPost(${data.postID})">Edit post</button>
                        <button type="button" onclick="deletePost(${data.postID})">Delete post</button>
                    </div>
                </div>
            </div>
            <div class="post-content">
                ${data.postCONTENT ? `<p>${escapeHtml(data.postCONTENT)}</p>` : ''}
                ${renderPostImagesHTML(data.postIMAGES || (data.postIMAGE ? [data.postIMAGE] : []))}
                ${data.postLAT && data.postLNG ? `
                    <div style="margin-top: 10px;">
                        <iframe src="https://www.google.com/maps/embed/v1/place?key=AIzaSyACss1f2TEk4tUgZXWtjJi-pxkL9dLhDqw&q=${data.postLAT},${data.postLNG}" 
                                style="width:100%; height:180px; border-radius:10px; border:0;" allowfullscreen></iframe>
                        ${data.postADDRESS ? `<p style="font-size: 12px; color: #666; margin-top: 5px; text-align: center;"><i class="bi bi-geo-alt-fill"></i> ${escapeHtml(data.postADDRESS)}</p>` : ''}
                    </div>
                ` : ''}
            </div>
            <div class="post-meta">
                <span id="likeCount-${data.postID}">0 likes</span>
                <span id="commentCount-${data.postID}">0 comments</span>
                <span id="shareCount-${data.postID}">0 shares</span>
            </div>
            <div class="post-actions">
                <button class="action-btn" id="likeBtn-${data.postID}" onclick="toggleLike(${data.postID})">
                    <i class="bi bi-heart"></i> Like
                </button>
                <button class="action-btn" onclick="focusComment(${data.postID})">
                    <i class="bi bi-chat"></i> Comment
                </button>
                <button type="button" class="action-btn" onclick="profileSharePost(${data.postID})"><i class="bi bi-share"></i> Share</button>
            </div>
            <div class="comment-section" id="comments-${data.postID}">
                <div id="commentList-${data.postID}"></div>
                <div class="comment-form">
                    <img src="${ME.pic}" style="width:32px; height:32px; border-radius:50%; object-fit:cover; flex-shrink:0;">
                    <input type="text" id="commentInput-${data.postID}" placeholder="Write a comment..."
                           onkeydown="if(event.key==='Enter') submitComment(${data.postID})">
                    <button onclick="submitComment(${data.postID})">Send</button>
                </div>
            </div>
        </div>`;
    }

    /* ── TOGGLE POST LIKE ── */
    async function toggleLike(postID) {
        const fd = new FormData();
        fd.append('action', 'toggleLike');
        fd.append('postID', postID);

        const res  = await fetch('profile.php', { method: 'POST', body: fd });
        const data = await res.json();

        if (data.success) {
            const btn   = document.getElementById(`likeBtn-${postID}`);
            const count = document.getElementById(`likeCount-${postID}`);
            const icon  = btn.querySelector('i');

            if (data.liked) {
                btn.classList.add('liked');
                icon.className = 'bi bi-heart-fill';
            } else {
                btn.classList.remove('liked');
                icon.className = 'bi bi-heart';
            }
            count.textContent = `${data.likes} ${data.likes == 1 ? 'like' : 'likes'}`;
        }
    }

    /* ── FOCUS COMMENT INPUT ── */
    function focusComment(postID) {
        const input = document.getElementById(`commentInput-${postID}`);
        if (input) input.focus();
    }

    /* ── SUBMIT COMMENT ── */
    async function submitComment(postID) {
        const input   = document.getElementById(`commentInput-${postID}`);
        const content = input.value.trim();
        if (!content) return;

        const fd = new FormData();
        fd.append('action', 'submitComment');
        fd.append('postID', postID);
        fd.append('commentContent', content);

        const res  = await fetch('profile.php', { method: 'POST', body: fd });
        const data = await res.json();

        if (data.success) {
            input.value = '';
            const list = document.getElementById(`commentList-${postID}`);
            list.insertAdjacentHTML('beforeend', buildCommentHTML(data));

            // Update comment count
            const countEl = document.getElementById(`commentCount-${postID}`);
            const current = parseInt(countEl.textContent);
            countEl.textContent = `${current + 1} ${current + 1 == 1 ? 'comment' : 'comments'}`;
        }
    }

    /* ── BUILD COMMENT HTML ── */
    function buildCommentHTML(data) {
        return `
        <div class="comment-item" id="comment-${data.commentID}">
            <img src="${data.userPROFILEPIC}" alt="Commenter">
            <div style="flex:1;">
                <div class="comment-bubble">
                    <b>${escapeHtml(data.userNAME)}</b>
                    <p>${escapeHtml(data.commentCONTENT)}</p>
                    <small>${data.commentDATE}</small>
                </div>
                <div class="comment-actions">
                    <button class="comment-like-btn" id="cLikeBtn-${data.commentID}"
                            onclick="toggleCommentLike(${data.commentID})">
                        <i class="bi bi-heart"></i>
                        <span id="cLikeCount-${data.commentID}">0</span>
                    </button>
                    <button class="comment-reply-btn" onclick="toggleReply(${data.commentID}, ${data.postID ?? 0})">
                        <i class="bi bi-reply"></i> Reply
                    </button>
                </div>
                <div class="reply-form" id="replyForm-${data.commentID}">
                    <img src="${ME.pic}" style="width:26px; height:26px; border-radius:50%; object-fit:cover; flex-shrink:0;">
                    <input type="text" placeholder="Write a reply..." id="replyInput-${data.commentID}">
                    <button onclick="submitReply(${data.commentID}, 0)">Reply</button>
                </div>
            </div>
        </div>`;
    }

    /* ── TOGGLE COMMENT LIKE ── */
    async function toggleCommentLike(commentID) {
        const fd = new FormData();
        fd.append('action', 'toggleCommentLike');
        fd.append('commentID', commentID);

        const res  = await fetch('profile.php', { method: 'POST', body: fd });
        const data = await res.json();

        if (data.success) {
            const btn   = document.getElementById(`cLikeBtn-${commentID}`);
            const count = document.getElementById(`cLikeCount-${commentID}`);
            const icon  = btn.querySelector('i');

            if (data.liked) {
                btn.classList.add('liked');
                icon.className = 'bi bi-heart-fill';
            } else {
                btn.classList.remove('liked');
                icon.className = 'bi bi-heart';
            }
            count.textContent = data.likes;
        }
    }

    /* ── TOGGLE REPLY FORM ── */
    function toggleReply(commentID, postID) {
        const form = document.getElementById(`replyForm-${commentID}`);
        const isVisible = form.style.display === 'flex';
        form.style.display = isVisible ? 'none' : 'flex';
        if (!isVisible) document.getElementById(`replyInput-${commentID}`).focus();
    }

    /* ── SUBMIT REPLY (posts as a comment on the same post) ── */
    async function submitReply(commentID, postID) {
        const input   = document.getElementById(`replyInput-${commentID}`);
        const content = input.value.trim();
        if (!content) return;

        // Find the post ID from the comment's parent if not passed
        if (!postID) {
            const commentEl = document.getElementById(`comment-${commentID}`);
            const postEl    = commentEl.closest('.post');
            postID = postEl ? postEl.id.replace('post-', '') : 0;
        }

        const fd = new FormData();
        fd.append('action', 'submitComment');
        fd.append('postID', postID);
        fd.append('commentContent', `↩ ${content}`); // prefix to indicate reply

        const res  = await fetch('profile.php', { method: 'POST', body: fd });
        const data = await res.json();

        if (data.success) {
            input.value = '';
            document.getElementById(`replyForm-${commentID}`).style.display = 'none';

            const list = document.getElementById(`commentList-${postID}`);
            list.insertAdjacentHTML('beforeend', buildCommentHTML(data));

            const countEl = document.getElementById(`commentCount-${postID}`);
            const current = parseInt(countEl.textContent);
            countEl.textContent = `${current + 1} ${current + 1 == 1 ? 'comment' : 'comments'}`;
        }
    }

    /* ── SHOW MORE / LESS COMMENTS ── */
    function toggleShowMore(postID, total) {
        const list   = document.getElementById(`commentList-${postID}`);
        const btn    = document.getElementById(`showMoreBtn-${postID}`);
        const hidden = list.querySelectorAll('.comment-hidden');

        if (hidden.length > 0) {
            // Show all
            hidden.forEach(c => c.classList.remove('comment-hidden'));
            btn.textContent = 'Show less';
        } else {
            // Hide again beyond limit
            const all = list.querySelectorAll('.comment-item');
            all.forEach((c, i) => {
                if (i >= COMMENTS_LIMIT) c.classList.add('comment-hidden');
            });
            btn.textContent = `Show ${total - COMMENTS_LIMIT} more comments`;
        }
    }

    /* ── ESCAPE HTML (prevent XSS in JS-built HTML) ── */
    function escapeHtml(str) {
        const d = document.createElement('div');
        d.textContent = str;
        return d.innerHTML;
    }

    function parsePostImages(value) {
        if (!value) return [];
        try {
            const parsed = JSON.parse(value);
            return Array.isArray(parsed) ? parsed.filter(Boolean) : [];
        } catch (e) {
            return value ? [value] : [];
        }
    }

    function renderPostImagesHTML(images) {
        images = Array.isArray(images) ? images.filter(Boolean) : [];
        if (!images.length) return '';
        return `<div class="post-image-grid">${images.map(src => `<img src="${escapeHtml(src)}" alt="Post image">`).join('')}</div>`;
    }

    function profileOpenPhotoLightbox(src) {
        if (!src) return;
        const box = document.getElementById('profilePhotoLightbox');
        const img = document.getElementById('profilePhotoLightboxImg');
        if (!box || !img) return;
        img.src = src;
        box.classList.add('open');
        box.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
    }
    function profileClosePhotoLightbox(ev) {
        if (ev && ev.target && ev.target.id === 'profilePhotoLightboxImg') return;
        const box = document.getElementById('profilePhotoLightbox');
        if (!box) return;
        box.classList.remove('open');
        box.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
        const img = document.getElementById('profilePhotoLightboxImg');
        if (img) img.src = '';
    }
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            profileClosePhotoLightbox();
            profileClosePostImageViewer(e);
        }
    });

    async function profileSharePost(postID) {
        const url = new URL('feed.php?post=' + postID, window.location.href).href;
        try {
            await navigator.clipboard.writeText(url);
        } catch (e) {}
        const fd = new FormData();
        fd.append('action', 'incrementShare');
        fd.append('postID', postID);
        const res = await fetch('profile.php', { method: 'POST', body: fd });
        let shares = null;
        try {
            const data = await res.json();
            if (data.success && data.shares != null) shares = data.shares;
        } catch (e) {}
        const el = document.getElementById('shareCount-' + postID);
        if (el && shares != null) {
            el.textContent = shares + ' shares';
        }
        alert('Link to this post copied. Paste it anywhere to share.');
    }

    function closeAllPostMenus() {
        document.querySelectorAll('.post-menu.open').forEach(menu => menu.classList.remove('open'));
    }

    function togglePostMenu(postID) {
        closeAllPostMenus();
        const menu = document.getElementById('postMenu-' + postID);
        if (menu) menu.classList.toggle('open');
    }

    document.addEventListener('click', function(event) {
        if (!event.target.closest('.post-header-actions')) {
            closeAllPostMenus();
        }
    });

    function openEditModal(postID) {
        closeAllPostMenus();
        const postEl = document.getElementById('post-' + postID);
        if (!postEl) return;

        editPostId = postID;
        const content = postEl.dataset.postcontent || '';
        const imageUrl = postEl.dataset.postimage || '';
        const imageUrls = parsePostImages(postEl.dataset.postimages || '');
        editPostLat = postEl.dataset.postlat ? parseFloat(postEl.dataset.postlat) : null;
        editPostLng = postEl.dataset.postlng ? parseFloat(postEl.dataset.postlng) : null;
        editPostAddress = postEl.dataset.postaddress || '';
        editOriginalImage = imageUrl;

        document.getElementById('editPostContent').value = content;
        document.getElementById('editPostLocationInput').value = editPostAddress;
        document.getElementById('editRemoveImage').checked = false;
        document.getElementById('editNewImageInput').value = '';

        const imageWrapper = document.getElementById('editImageWrapper');
        const previewGrid = document.getElementById('editImagePreviewGrid');
        const existingImages = imageUrls.length ? imageUrls : (imageUrl ? [imageUrl] : []);
        if (existingImages.length) {
            previewGrid.innerHTML = existingImages.map(src => `<img src="${escapeHtml(src)}" alt="Current post image">`).join('');
            imageWrapper.style.display = 'grid';
        } else {
            previewGrid.innerHTML = '';
            imageWrapper.style.display = 'none';
        }

        const overlay = document.getElementById('editModalOverlay');
        overlay.classList.add('open');
        showEditMap();
        updateEditMap();
    }

    function closeEditModal(event) {
        if (event && event.target !== event.currentTarget) return;
        const overlay = document.getElementById('editModalOverlay');
        if (!overlay) return;
        overlay.classList.remove('open');
        document.getElementById('editPostContent').value = '';
        document.getElementById('editPostLocationInput').value = '';
        document.getElementById('editNewImageInput').value = '';
        document.getElementById('editRemoveImage').checked = false;
        const imageWrapper = document.getElementById('editImageWrapper');
        imageWrapper.style.display = 'none';
        const previewGrid = document.getElementById('editImagePreviewGrid');
        if (previewGrid) previewGrid.innerHTML = '';
        editPostId = null;
    }

    async function editPost(postID) {
        openEditModal(postID);
    }

    let deletePostId = null;

    function deletePost(postID) {
        closeAllPostMenus();
        deletePostId = postID;
        document.getElementById('deleteModalOverlay').classList.add('open');
    }

    function closeDeleteModal(event) {
        if (event && event.target !== event.currentTarget) return;
        const overlay = document.getElementById('deleteModalOverlay');
        if (!overlay) return;
        overlay.classList.remove('open');
        deletePostId = null;
    }

    async function confirmDeletePost() {
        if (!deletePostId) return;
        const fd = new FormData();
        fd.append('action', 'deletePost');
        fd.append('postID', deletePostId);
        const res = await fetch('profile.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) {
            const postEl = document.getElementById('post-' + deletePostId);
            if (postEl) postEl.remove();
            const stat = document.getElementById('postCountStat');
            if (stat) {
                const count = parseInt(stat.textContent) || 0;
                stat.textContent = Math.max(0, count - 1);
            }
            closeDeleteModal();
        } else {
            alert(data.message || 'Unable to delete post.');
        }
    }

    async function saveEditPost() {
        if (!editPostId) return;
        const content = document.getElementById('editPostContent').value.trim();
        const removeImage = document.getElementById('editRemoveImage').checked;
        const fileInput = document.getElementById('editNewImageInput');

        const fd = new FormData();
        fd.append('action', 'editPost');
        fd.append('postID', editPostId);
        fd.append('postContent', content);
        fd.append('postLat', editPostLat !== null ? editPostLat : '');
        fd.append('postLng', editPostLng !== null ? editPostLng : '');
        fd.append('postAddress', editPostAddress);
        fd.append('removeImage', removeImage ? '1' : '0');
        Array.from(fileInput.files || []).forEach(file => fd.append('postImage[]', file));

        const res = await fetch('profile.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) {
            const postEl = document.getElementById('post-' + editPostId);
            if (postEl) {
                postEl.dataset.postcontent = data.postCONTENT || '';
                postEl.dataset.postimage = data.postIMAGE || '';
                postEl.dataset.postimages = JSON.stringify(data.postIMAGES || []);
                postEl.dataset.postlat = data.postLAT ?? '';
                postEl.dataset.postlng = data.postLNG ?? '';
                postEl.dataset.postaddress = data.postADDRESS || '';

                const contentContainer = postEl.querySelector('.post-content');
                if (contentContainer) {
                    contentContainer.innerHTML = renderPostContentHTML(data);
                }
            }
            closeEditModal();
        } else {
            alert(data.message || 'Unable to save changes.');
        }
    }

    function renderPostContentHTML(post) {
        let html = '';
        if (post.postCONTENT) {
            html += `<p>${escapeHtml(post.postCONTENT)}</p>`;
        }
        html += renderPostImagesHTML(post.postIMAGES || (post.postIMAGE ? [post.postIMAGE] : []));
        if (post.postLAT && post.postLNG) {
            html += `\n                    <div style="margin-top: 10px;">\n                        <iframe src="https://www.google.com/maps/embed/v1/place?key=AIzaSyACss1f2TEk4tUgZXWtjJi-pxkL9dLhDqw&q=${post.postLAT},${post.postLNG}" style="width:100%; height:180px; border-radius:10px; border:0;" allowfullscreen></iframe>`;
            if (post.postADDRESS) {
                html += `\n                            <p style="font-size: 12px; color: #666; margin-top: 5px; text-align: center;"><i class="bi bi-geo-alt-fill"></i> ${escapeHtml(post.postADDRESS)}</p>`;
            }
            html += '\n                    </div>';
        }
        return html;
    }

    function toggleEditMap() {
        const mapDiv = document.getElementById('editLocationMap');
        if (mapDiv.style.display === 'none' || mapDiv.style.display === '') {
            mapDiv.style.display = 'block';
            updateEditMap();
        } else {
            mapDiv.style.display = 'none';
        }
    }

    function showEditMap() {
        const mapDiv = document.getElementById('editLocationMap');
        mapDiv.style.display = 'block';
    }

    function previewEditImage(event) {
        const files = Array.from(event.target.files || []);
        const imageWrapper = document.getElementById('editImageWrapper');
        const previewGrid = document.getElementById('editImagePreviewGrid');
        const removeCheckbox = document.getElementById('editRemoveImage');

        if (files.length) {
            previewGrid.innerHTML = files.map(file => `<img src="${URL.createObjectURL(file)}" alt="Selected image">`).join('');
            imageWrapper.style.display = 'grid';
        } else if (!previewGrid.innerHTML.trim()) {
            imageWrapper.style.display = 'none';
        }
    }

    function clearEditLocation() {
        editPostLat = null;
        editPostLng = null;
        editPostAddress = '';
        document.getElementById('editPostLocationInput').value = '';
        updateEditMap();
    }

    function updateEditMap() {
        const mapDiv = document.getElementById('editLocationMap');
        if (!editMap || !editMarker) return;

        if (editPostLat && editPostLng) {
            const pos = {lat: editPostLat, lng: editPostLng};
            editMap.setCenter(pos);
            editMap.setZoom(15);
            editMarker.setPosition(pos);
            editMarker.setVisible(true);
            mapDiv.style.display = 'block';
        } else {
            editMarker.setVisible(false);
            editMap.setCenter({lat: 0, lng: 0});
            editMap.setZoom(2);
        }
    }

    // Location variables
    let postLat = null, postLng = null, postAddress = '';
    let editPostId = null, editPostLat = null, editPostLng = null, editPostAddress = '';
    let editOriginalImage = '';
    let map, marker, editMap, editMarker, editAutocomplete;

    function initMap() {
        const input = document.getElementById('postLocationInput');
        const autocomplete = new google.maps.places.Autocomplete(input);
        autocomplete.addListener('place_changed', function() {
            const place = autocomplete.getPlace();
            if (place.geometry) {
                postLat = place.geometry.location.lat();
                postLng = place.geometry.location.lng();
                postAddress = place.formatted_address;
                updateMap();
            }
        });

        // Init map
        map = new google.maps.Map(document.getElementById('locationMap'), {
            center: {lat: 0, lng: 0},
            zoom: 2,
            disableDefaultUI: true,
            zoomControl: true
        });
        marker = new google.maps.Marker({
            map: map,
            position: {lat: 0, lng: 0},
            visible: false
        });
        map.addListener('click', function(event) {
            postLat = event.latLng.lat();
            postLng = event.latLng.lng();
            reverseGeocode(event.latLng);
            updateMap();
        });

        editMap = new google.maps.Map(document.getElementById('editLocationMap'), {
            center: {lat: 0, lng: 0},
            zoom: 2,
            disableDefaultUI: true,
            zoomControl: true
        });
        editMarker = new google.maps.Marker({
            map: editMap,
            position: {lat: 0, lng: 0},
            visible: false
        });
        editMap.addListener('click', function(event) {
            editPostLat = event.latLng.lat();
            editPostLng = event.latLng.lng();
            reverseGeocodeEdit(event.latLng);
            updateEditMap();
        });

        const editInput = document.getElementById('editPostLocationInput');
        editAutocomplete = new google.maps.places.Autocomplete(editInput);
        editAutocomplete.addListener('place_changed', function() {
            const place = editAutocomplete.getPlace();
            if (place.geometry) {
                editPostLat = place.geometry.location.lat();
                editPostLng = place.geometry.location.lng();
                editPostAddress = place.formatted_address;
                updateEditMap();
            }
        });
    }

    function updateMap() {
        if (postLat && postLng && map && marker) {
            const pos = {lat: postLat, lng: postLng};
            map.setCenter(pos);
            map.setZoom(15);
            marker.setPosition(pos);
            marker.setVisible(true);
        }
    }

    function reverseGeocode(latLng) {
        const geocoder = new google.maps.Geocoder();
        geocoder.geocode({location: latLng}, function(results, status) {
            if (status === 'OK' && results[0]) {
                postAddress = results[0].formatted_address;
                document.getElementById('postLocationInput').value = postAddress;
            }
        });
    }

    function reverseGeocodeEdit(latLng) {
        const geocoder = new google.maps.Geocoder();
        geocoder.geocode({location: latLng}, function(results, status) {
            if (status === 'OK' && results[0]) {
                editPostAddress = results[0].formatted_address;
                document.getElementById('editPostLocationInput').value = editPostAddress;
            }
        });
    }

    function toggleMap() {
        const mapDiv = document.getElementById('locationMap');
        if (mapDiv.style.display === 'none') {
            if (!window.google || !map) {
                alert('Map is still loading. Please try again in a moment.');
                return;
            }
            mapDiv.style.display = 'block';
            if (postLat && postLng) {
                updateMap();
            }
            setTimeout(function () {
                google.maps.event.trigger(map, 'resize');
                if (postLat && postLng) {
                    updateMap();
                } else {
                    map.setCenter({lat: 14.5995, lng: 120.9842});
                    map.setZoom(12);
                }
            }, 80);
        } else {
            mapDiv.style.display = 'none';
        }
    }
</script>
<script async defer src="https://maps.googleapis.com/maps/api/js?key=AIzaSyACss1f2TEk4tUgZXWtjJi-pxkL9dLhDqw&libraries=places,geometry&callback=initMap"></script>

</body>
</html>
