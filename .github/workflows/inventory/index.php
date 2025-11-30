<?php
session_start();
if (isset($_SESSION['role'])) {
    if ($_SESSION['role'] === 'admin') {
        header("Location: admin_dashboard.php");
        exit();
    } elseif ($_SESSION['role'] === 'user') {
        header("Location: user_dashboard.php");
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HardwareHub - Inventory Management System</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: "Poppins", sans-serif;
        }
        
        body {
            background: linear-gradient(rgba(0, 0, 0, 0.7), rgba(0, 0, 0, 0.7)), 
                        url('https://images.unsplash.com/photo-1581094794329-c6dbb6c8d0d3?ixlib=rb-4.0.3&ixid=M3wxMjA3fDB8MHxwaG90by1wYWdlfHx8fGVufDB8fHx8fA%3D%3D&auto=format&fit=crop&w=2070&q=80');
            background-size: cover;
            background-position: center;
            background-attachment: fixed;
            color: white;
            min-height: 100vh;
        }
        
        .navbar {
            background: rgba(0, 0, 0, 0.9);
            padding: 1rem 2rem;
            display: flex;
            justify-content: center;
            align-items: center;
            position: fixed;
            width: 100%;
            top: 0;
            z-index: 1000;
            backdrop-filter: blur(10px);
        }
        
        .nav-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            width: 100%;
            max-width: 1200px;
        }
        
        .logo {
            font-size: 1.8rem;
            font-weight: bold;
            color: #fff;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .logo span {
            color: #4CAF50;
        }
        
        .nav-links {
            display: flex;
            gap: 2rem;
            list-style: none;
            position: absolute;
            left: 50%;
            transform: translateX(-50%);
        }
        
        .nav-links a {
            color: white;
            text-decoration: none;
            padding: 0.5rem 1rem;
            border-radius: 5px;
            transition: all 0.3s;
            font-weight: 500;
        }
        
        .nav-links a:hover {
            background: #4CAF50;
            transform: translateY(-2px);
        }
        
        .hero {
            height: 100vh;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            text-align: center;
            padding: 0 2rem;
            position: relative;
        }
        
        .hero-content {
            max-width: 800px;
            animation: fadeInUp 1s ease;
        }
        
        .hero h1 {
            font-size: 3.5rem;
            margin-bottom: 1.5rem;
            background: linear-gradient(45deg, #4CAF50, #2196F3);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .hero p {
            font-size: 1.3rem;
            margin-bottom: 2.5rem;
            line-height: 1.6;
            color: #e0e0e0;
        }
        
        .cta-buttons {
            display: flex;
            gap: 1.5rem;
            justify-content: center;
            flex-wrap: wrap;
        }
        
        .cta-btn {
            padding: 15px 35px;
            border: none;
            border-radius: 8px;
            font-size: 1.1rem;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.3s;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .cta-primary {
            background: #4CAF50;
            color: white;
            box-shadow: 0 4px 15px rgba(76, 175, 80, 0.3);
        }
        
        .cta-primary:hover {
            background: #45a049;
            transform: translateY(-3px);
            box-shadow: 0 6px 20px rgba(76, 175, 80, 0.4);
        }
        
        .cta-secondary {
            background: transparent;
            color: white;
            border: 2px solid #4CAF50;
        }
        
        .cta-secondary:hover {
            background: #4CAF50;
            transform: translateY(-3px);
        }
        
        .features, .about {
            padding: 5rem 2rem;
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
        }
        
        .features h2, .about h2 {
            text-align: center;
            font-size: 2.5rem;
            margin-bottom: 3rem;
            color: white;
        }
        
        .feature-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 2rem;
            max-width: 1200px;
            margin: 0 auto;
        }
        
        .feature-card {
            background: rgba(255, 255, 255, 0.1);
            padding: 2.5rem;
            border-radius: 15px;
            text-align: center;
            backdrop-filter: blur(5px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            transition: all 0.3s;
            position: relative;
            overflow: hidden;
            min-height: 350px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        
        .feature-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.1), transparent);
            transition: left 0.5s;
        }
        
        .feature-card:hover::before {
            left: 100%;
        }
        
        .feature-card:hover {
            transform: translateY(-10px);
            border-color: #4CAF50;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
        }
        
        .feature-icon {
            font-size: 3.5rem;
            margin-bottom: 1.5rem;
            display: block;
        }
        
        .feature-card h3 {
            font-size: 1.5rem;
            margin-bottom: 1rem;
            color: #6ede3eff;
        }
        
        .feature-card p {
            color: #e0e0e0;
            line-height: 1.6;
        }
        
        /* About Section Styles */
        .about-content {
            max-width: 1200px;
            margin: 0 auto;
            text-align: center;
        }
        
        .about-text {
            font-size: 1.2rem;
            line-height: 1.8;
            margin-bottom: 3rem;
            color: #e0e0e0;
            max-width: 800px;
            margin-left: auto;
            margin-right: auto;
        }
        
        .team-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 2rem;
            max-width: 1200px;
            margin: 0 auto;
        }
        
        .team-member {
            background: rgba(255, 255, 255, 0.1);
            padding: 2.5rem;
            border-radius: 15px;
            text-align: center;
            backdrop-filter: blur(5px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            transition: all 0.3s;
            min-height: 350px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            position: relative;
            overflow: hidden;
        }
        
        .team-member::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.1), transparent);
            transition: left 0.5s;
        }
        
        .team-member:hover::before {
            left: 100%;
        }
        
        .team-member:hover {
            transform: translateY(-10px);
            border-color: #4CAF50;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
        }
        
        .team-member img {
            width: 170px;
            height: 170px;
            border-radius: 50%;
            object-fit: cover;
            margin-bottom: 1.5rem;
            border: 3px solid #4CAF50;
        }
        
        .team-member h4 {
            font-size: 1.5rem;
            margin-bottom: 0.5rem;
            color: #70e774ff;
        }
        
        .team-member .role {
            color: #bbb;
            font-style: italic;
            margin-bottom: 1rem;
            font-size: 1.1rem;
        }
        
        .stats {
            padding: 4rem 2rem;
            background: rgba(0, 0, 0, 0.8);
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 2rem;
            max-width: 1200px;
            margin: 0 auto;
            text-align: center;
        }
        
        .stat-item {
            padding: 2rem;
        }
        
        .stat-number {
            font-size: 3rem;
            font-weight: bold;
            color: #4CAF50;
            margin-bottom: 0.5rem;
        }
        
        .stat-label {
            font-size: 1.1rem;
            color: #e0e0e0;
        }
        
        .footer {
            background: rgba(0, 0, 0, 0.9);
            padding: 2rem;
            text-align: center;
            color: #e0e0e0;
        }
        
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        @media (max-width: 768px) {
            .nav-links {
                display: none;
            }
            
            .nav-container {
                justify-content: center;
            }
            
            .hero h1 {
                font-size: 2.5rem;
            }
            
            .hero p {
                font-size: 1.1rem;
            }
            
            .cta-buttons {
                flex-direction: column;
                align-items: center;
            }
            
            .cta-btn {
                width: 100%;
                max-width: 300px;
                justify-content: center;
            }
            
            .feature-grid {
                grid-template-columns: 1fr;
            }
            
            .team-grid {
                grid-template-columns: 1fr;
            }
            
            .feature-card, .team-member {
                min-height: 300px;
                padding: 2rem;
            }
            
            .team-member img {
                width: 140px;
                height: 140px;
            }
        }
        
        /* Scroll animations */
        .fade-in {
            opacity: 0;
            transform: translateY(30px);
            transition: all 0.6s ease;
        }
        
        .fade-in.visible {
            opacity: 1;
            transform: translateY(0);
        }
    </style>
</head>
<body>
   
    <nav class="navbar">
        <div class="nav-container">
            <div class="logo">🔧 Hardware<span>Hub</span></div>
            <ul class="nav-links">
                <li><a href="#features">Features</a></li>
                <li><a href="#about">About</a></li>
            </ul>
          
            <div></div>
        </div>
    </nav>

 
    <section class="hero">
        <div class="hero-content">
            <h1>Hardware Hub</h1>
            <p>Streamline your hardware store operations with our comprehensive inventory management system. Track products, manage stock levels, optimize workflow, and grow your business efficiently.</p>
            
            <div class="cta-buttons">
                <a href="login.php" class="cta-btn cta-primary">
                    <span>🔑</span>
                    Login
                </a>
                <a href="register_account.php" class="cta-btn cta-secondary">
                    <span>👤</span>
                    Register
                </a>
            </div>
        </div>
    </section>

    <!-- Features Section -->
    <section class="features" id="features">
        <h2>Powerful Features</h2>
        <div class="feature-grid">
            <div class="feature-card fade-in">
                <div class="feature-icon">📦</div>
                <h3>Product Management</h3>
                <p>Easily add, edit, and track all your products with detailed information, categories, and real-time stock updates.</p>
            </div>
            
            <div class="feature-card fade-in">
                <div class="feature-icon">📊</div>
                <h3>Real-time Analytics</h3>
                <p>Get instant insights into your inventory levels, sales trends, stock requirements, and business performance.</p>
            </div>
            
            <div class="feature-card fade-in">
                <div class="feature-icon">👥</div>
                <h3>Role-based Access</h3>
                <p>Secure multi-level access control with separate dashboards for administrators and regular users.</p>
            </div>
            
            <div class="feature-card fade-in">
                <div class="feature-icon">⚠️</div>
                <h3>Stock Alerts</h3>
                <p>Automatic notifications for low stock and out-of-stock items to prevent inventory shortages.</p>
            </div>
            
            <div class="feature-card fade-in">
                <div class="feature-icon">📈</div>
                <h3>Sales Tracking</h3>
                <p>Monitor all sales transactions, generate comprehensive reports, and analyze customer purchasing patterns.</p>
            </div>
            
            <div class="feature-card fade-in">
                <div class="feature-icon">🔒</div>
                <h3>Secure & Reliable</h3>
                <p>Enterprise-grade security with encrypted data, secure authentication, and reliable backup systems.</p>
            </div>
        </div>
    </section>

    <!-- About Section -->
    <section class="about" id="about">
        <h2>About HardwareHub</h2>
        <div class="about-content">
            <div class="about-text fade-in">
                <p>HardwareHub is a comprehensive inventory management solution designed specifically for hardware stores. Our platform helps businesses streamline their operations, reduce costs, and improve customer satisfaction through intelligent inventory tracking and management.</p>
            </div>
            
            <div class="team-grid">
                <div class="team-member fade-in">
                    <img src="images/ceo.jpg" alt="CEO">
                    <h4>Steeve James Ramos</h4>
                    <div class="role">CEO & Founder</div>
                </div>
                
                <div class="team-member fade-in">
                    <img src="images/president.jpg" alt="President">
                    <h4>Abdurahman Ibrahim</h4>
                    <div class="role">President</div>
                </div>
                
                <div class="team-member fade-in">
                    <img src="images/vicepresident.jpg" alt="Vice President">
                    <h4>Mark John Redera</h4>
                    <div class="role">Vice President</div>
                </div>
                
                <div class="team-member fade-in">
                    <img src="images/admin.jpg" alt="Administrator">
                    <h4>Justin Macabihag</h4>
                    <div class="role">Administrator</div>
                </div>
            </div>
        </div>
    </section>

       <script>
        // Scroll animations
        function checkScroll() {
            const elements = document.querySelectorAll('.fade-in');
            elements.forEach(element => {
                const elementTop = element.getBoundingClientRect().top;
                const elementVisible = 150;
                if (elementTop < window.innerHeight - elementVisible) {
                    element.classList.add('visible');
                }
            });
        }

        // Navbar background on scroll
        window.addEventListener('scroll', function() {
            const navbar = document.querySelector('.navbar');
            if (window.scrollY > 100) {
                navbar.style.background = 'rgba(0, 0, 0, 0.95)';
            } else {
                navbar.style.background = 'rgba(0, 0, 0, 0.9)';
            }
            checkScroll();
        });

        // Smooth scrolling for navigation links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            });
        });

        // Initial check for scroll animations
        document.addEventListener('DOMContentLoaded', function() {
            checkScroll();
        });
    </script>
</body>
</html>