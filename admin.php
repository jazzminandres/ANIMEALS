<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ANIMEALS: Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        * {
            margin: 0; padding: 0; box-sizing: border-box;
            font-family: 'Inter', 'Poppins', sans-serif;
        }

        body {
            background: #f0f2f5;
            height: 100vh;
            display: flex;
        }

        /* ========== SIDEBAR (Authority Blue) ========== */
        .sidebar {
            width: 280px;
            background: #1a222d;
            color: #fff;
            display: flex;
            flex-direction: column;
            padding: 25px 20px;
            height: 100vh;
        }

        .brand {
            font-size: 22px;
            font-weight: 800;
            margin-bottom: 40px;
            display: flex;
            align-items: center;
            gap: 10px;
            color: #3b82f6;
        }

        .menu-label {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #64748b;
            margin-bottom: 15px;
        }

        .menu { display: flex; flex-direction: column; gap: 8px; }

        .menu a {
            text-decoration: none;
            color: #94a3b8;
            padding: 12px 15px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            gap: 12px;
            transition: 0.3s;
        }

        .menu a:hover, .menu a.active {
            background: #3b82f6;
            color: #fff;
        }

        /* ========== MAIN CONTENT ========== */
        .main {
            flex: 1;
            padding: 30px;
            overflow-y: auto;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
        }

        /* ========== STAT CARDS ========== */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: #fff;
            padding: 20px;
            border-radius: 15px;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);
            display: flex;
            flex-direction: column;
        }

        .stat-card i { font-size: 24px; color: #3b82f6; margin-bottom: 10px; }
        .stat-card h3 { font-size: 24px; color: #1e293b; }
        .stat-card p { color: #64748b; font-size: 14px; }

        /* ========== CHARTS SECTION ========== */
        .charts-container {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 20px;
            margin-bottom: 30px;
        }

        .chart-box {
            background: #fff;
            padding: 20px;
            border-radius: 15px;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);
        }

        /* ========== MANAGEMENT TABLE ========== */
        .data-section {
            background: #fff;
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);
        }

        .table-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            text-align: left;
            padding: 12px;
            border-bottom: 2px solid #f1f5f9;
            color: #64748b;
            font-size: 14px;
        }

        td {
            padding: 15px 12px;
            border-bottom: 1px solid #f1f5f9;
            font-size: 14px;
            color: #334155;
        }

        .status {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .status-active { background: #dcfce7; color: #15803d; }
        .status-pending { background: #fef9c3; color: #854d0e; }
        .status-report { background: #fee2e2; color: #991b1b; }

        .action-btn {
            border: none; background: none; cursor: pointer; color: #64748b; font-size: 18px;
        }

        .btn-add {
            background: #3b82f6; color: #fff; padding: 10px 20px;
            border-radius: 8px; border: none; cursor: pointer; font-weight: 600;
        }

          .logout-btn { 
            margin-top: auto; 
            background: rgba(0,0,0,0.1); 
            text-align: center; 
            padding: 12px; 
            border-radius: 15px; 
            color: white; 
            text-decoration: none; 
            font-weight: 600; 
            transition: 0.3s;
        }
        .logout-btn:hover { background: #ff4d4d; }

    </style>
</head>
<body>

    <div class="sidebar">
        <div class="brand">
            <i class="bi bi-shield-lock-fill"></i> ADMINS
        </div>

        <p class="menu-label">Main Menu</p>
        <div class="menu">
                                    <a href="#"><i class="bi bi-shop"></i> Profile</a>

            <a href="#" class="active"><i class="bi bi-speedometer2"></i> Overview</a>
            <a href="#"><i class="bi bi-shop"></i> Manage Shops</a>
            <a href="#"><i class="bi bi-people"></i> Users List</a>
            <a href="#"><i class="bi bi-flag"></i> Reports <span style="background:#ef4444; color:white; border-radius:50%; padding:2px 7px; font-size:10px; margin-left:auto;">5</span></a>

        </div>

        <p class="menu-label" style="margin-top:30px;">Finance</p>
        <div class="menu">
            <a href="#"><i class="bi bi-cash-stack"></i> Commissions</a>
            <a href="#"><i class="bi bi-graph-up-arrow"></i> Analytics</a>
        </div>

        <a href="losign.html" class="logout.btn" style="margin-top: auto; text-decoration: none; color: #ef4444;">
            <i class="bi bi-box-arrow-right"></i> Log out</a>
    </div>

    <div class="main">
        <div class="header">
            <div>
                <h1 style="color: #1e293b;">OVERVIEW</h1>
                <p style="color: #64748b;">Welcome back, Admin Jazzmin.</p>
            </div>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <i class="bi bi-people"></i>
                <h3>1,240</h3>
                <p>Total Students</p>
            </div>
            <div class="stat-card">
                <i class="bi bi-shop-window"></i>
                <h3>42</h3>
                <p>Active Sellers</p>
            </div>
            <div class="stat-card">
                <i class="bi bi-wallet2"></i>
                <h3>₱45,200</h3>
                <p>Commission Revenue</p>
            </div>
            <div class="stat-card">
                <i class="bi bi-exclamation-triangle"></i>
                <h3>12</h3>
                <p>Pending Reports</p>
            </div>
        </div>

        <div class="charts-container">
            <div class="chart-box">
                <h4 style="margin-bottom:20px;">Revenue Growth (Commission 10%)</h4>
                <canvas id="revenueChart" height="150"></canvas>
            </div>
            <div class="chart-box">
                <h4 style="margin-bottom:20px;">User Distribution</h4>
                <canvas id="userChart"></canvas>
            </div>
        </div>

        <div class="data-section">
            <div class="table-header">
                <h3>Shop Management & Verifications</h3>
                <div class="search" style="border: 1px solid #e2e8f0; padding: 5px 15px; border-radius: 8px;">
                    <i class="bi bi-search"></i>
                    <input type="text" placeholder="Search shop..." style="border:none; outline:none; padding:5px;">
                </div>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Shop Name</th>
                        <th>Owner</th>
                        <th>Commission Paid</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><b>Canteen Central</b></td>
                        <td>Juan Dela Cruz</td>
                        <td>₱12,400.00</td>
                        <td><span class="status status-active">Verified</span></td>
                        <td>
                            <button class="action-btn"><i class="bi bi-pencil-square"></i></button>
                            <button class="action-btn" style="color:#ef4444;"><i class="bi bi-trash"></i></button>
                        </td>
                    </tr>
                    <tr>
                        <td><b>Wings & Flavour</b></td>
                        <td>Maria Clara</td>
                        <td>₱8,200.00</td>
                        <td><span class="status status-report">Reported</span></td>
                        <td>
                            <button class="action-btn"><i class="bi bi-eye"></i></button>
                            <button class="action-btn" style="color:#ef4444;"><i class="bi bi-slash-circle"></i></button>
                        </td>
                    </tr>
                    <tr>
                        <td><b>The Personal Pour</b></td>
                        <td>Student Entrep</td>
                        <td>₱0.00</td>
                        <td><span class="status status-pending">Pending</span></td>
                        <td>
                            <button class="action-btn" style="color:#22c55e;"><i class="bi bi-check-circle-fill"></i></button>
                            <button class="action-btn" style="color:#ef4444;"><i class="bi bi-x-circle-fill"></i></button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <script>
        // Revenue Line Chart
        const revCtx = document.getElementById('revenueChart').getContext('2d');
        new Chart(revCtx, {
            type: 'line',
            data: {
                labels: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
                datasets: [{
                    label: 'Daily Commission (₱)',
                    data: [500, 800, 1200, 950, 1500, 2000, 1800],
                    borderColor: '#3b82f6',
                    tension: 0.4,
                    fill: true,
                    backgroundColor: 'rgba(59, 130, 246, 0.1)'
                }]
            }
        });

        // User Doughnut Chart
        const userCtx = document.getElementById('userChart').getContext('2d');
        new Chart(userCtx, {
            type: 'doughnut',
            data: {
                labels: ['Students', 'Sellers'],
                datasets: [{
                    data: [1240, 42],
                    backgroundColor: ['#3b82f6', '#1e293b'],
                    borderWidth: 0
                }]
            },
            options: { cutout: '70%' }
        });
    </script>
</body>
</html>