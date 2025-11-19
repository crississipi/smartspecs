<?php
require_once 'config.php';

// Check if user is logged in using your existing function
$isLoggedIn = isLoggedIn();
$userName = $_SESSION['user_email'] ?? 'User Name';
$userEmail = $_SESSION['user_email'] ?? 'username@gmail.com';
$nightMode = false;

// Get user preferences if logged in
if ($isLoggedIn) {
    $userInfo = getCurrentUserInfo();
    $userName = $userInfo['name'] ?? explode('@', $userEmail)[0];
    $nightMode = isset($userInfo['preferences']['night_mode']) ? (bool)$userInfo['preferences']['night_mode'] : false;
}

$bodyClass = $nightMode ? 'night' : '';
$loginCardClass = $nightMode ? 'night' : '';

// Get user threads if logged in
$userThreads = [];
if ($isLoggedIn) {
    $userThreads = get_user_threads($_SESSION['user_id']);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SmartSpecs</title>
    <link rel="stylesheet" href="styles.css"/>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Commissioner:wght@100..900&display=swap" rel="stylesheet">
    <link rel="icon" href="assets/favicon.png" type="image/png" />
    <script src="https://accounts.google.com/gsi/client" async defer></script>
</head>
<body class="<?php echo $bodyClass; ?>">
    <main>
        <div class="notif hidden" id="notif">
            <img src="assets/warning.png" alt="status icon" draggable="false" id="statusIcon"/>
            <span id="notifText">Error: Thread failed to load.</span>
        </div>
        
        <!-- Login/Register Section -->
        <div class="login-page <?php echo $isLoggedIn ? 'hidden' : ''; ?>" id="loginPage">
            <div class="login-card <?php echo $loginCardClass; ?>" id="loginCard">
                <img src="assets/<?php echo $nightMode ? 'light/' : ''; ?>favicon.png" alt="logo image" class="logo-image"/>
                
                <!-- Login Form -->
                <div class="register" id="register">
                    <h2>Login</h2>
                    <form class="submit-form" id="loginForm">
                        <input type="email" name="userEmail" id="userEmail" placeholder="Email Address" required/>
                        <input type="password" name="userPassword" id="userPassword" placeholder="Password" required autocomplete="false"/>
                        <button type="button" class="forgot-pass-btn" id="forgotPassBtn">forgot password?</button>
                        <button type="submit" id="loginAcc">LOG IN</button>
                    </form>
                    <span>Don't have an account? <button type="button" class="link-btn" id="showSignUpBtn">Sign up</button></span>
                    <span>or continue with </span>
                    <button type="button" id="googleLoginBtn" class="google-btn">
                        <img src="assets/google.png" alt="google logo" />
                        Google Account
                    </button>
                </div>
                
                <!-- Signup Form -->
                <div class="signup hidden" id="signup">
                    <h2>Create Account</h2>
                    <form class="submit-form" id="signupForm">
                        <input type="email" name="signupEmail" id="signupEmail" placeholder="Email Address" required/>
                        <input type="password" name="signupPassword" id="signupPassword" placeholder="Password" required autocomplete="false"/>
                        <input type="password" name="confirmPassword" id="confirmPassword" placeholder="Confirm Password" required autocomplete="false"/>
                        <span id="signupPassWarning" class="hidden">Password should contain minimum of 8 characters.</span>
                        <span id="signupConPassWarning" class="hidden">Password does not match.</span>
                        <button type="submit" id="signupAcc">SIGN UP</button>
                    </form>
                    <span>Already have an account? <button type="button" class="link-btn" id="showLoginBtn">Log in</button></span>
                    <span>or continue with </span>
                    <button type="button" id="googleSignupBtn" class="google-btn">
                        <img src="assets/google.png" alt="google logo" />
                        Google Account
                    </button>
                </div>
                
                <!-- Forgot Password Flow -->
                <div class="forgot-pass hidden" id="forgotPass">
                    <div id="step1">
                        <h2>Forgot Password</h2>
                        <p>Please enter your email account. Make sure it is registered in our site. <span>Or you can <button type="button" id="registerBtn">register here.</button></span></p>
                        <form class="submit-form" id="forgotPassForm">
                            <input type="email" name="forgotEmail" id="forgotEmail" placeholder="Valid Email Address" required/>
                            <div class="button-group">
                                <button type="button" id="backToRegister">
                                    <img src="assets/<?php echo $nightMode ? 'light/' : ''; ?>arrow.png" alt="back logo" class="back-image"/>
                                </button>
                                <button type="submit" id="goToStep2">Next</button>
                            </div>
                        </form>
                    </div>
                    <div id="step2" class="hidden">
                        <h2>Forgot Password</h2>
                        <p>We're almost there. We sent you an OTP. Check your email inbox and type the OTP below.</p>
                        <form id="digitInputs" class="submit-form" id="verifyOtpForm">
                            <input type="number" name="dig1" maxlength="1" required>
                            <input type="number" name="dig2" maxlength="1" required>
                            <input type="number" name="dig3" maxlength="1" required>
                            <input type="number" name="dig4" maxlength="1" required>
                            <input type="number" name="dig5" maxlength="1" required>
                            <div class="button-group">
                                <button type="button" id="backToStep1">
                                    <img src="assets/<?php echo $nightMode ? 'light/' : ''; ?>arrow.png" alt="back logo" class="back-image"/>
                                </button>
                                <button type="submit" id="goToStep3">Next</button>
                            </div>
                        </form>
                    </div>
                    <div id="step3" class="hidden">
                        <h2>Change Password</h2>
                        <p>To complete the recovery of your account, you must set a new password and re-type it below.</p>
                        <form class="submit-form" id="resetPassForm">
                            <input type="password" name="newPass" id="newPass" placeholder="New Password">
                            <span id="newPassWarning">Password should contain minimum of 8 characters.</span>
                            <input type="password" name="confirmPass" id="confirmPass" placeholder="Confirm New Password">
                            <span id="conPassWarning" class="hidden">Password does not match.</span>
                            <div class="button-group">
                                <button type="button" id="backToStep2">
                                    <img src="assets/<?php echo $nightMode ? 'light/' : ''; ?>arrow.png" alt="back logo" class="back-image"/>
                                </button>
                                <button type="submit" id="submitBtn">Submit</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Header -->
        <header> 
            <span>
                <img src="assets/<?php echo $nightMode ? 'light/' : ''; ?>favicon.png" alt="logo image" class="logo-image"/>
                <h1>SmartSpecs</h1>
                <img class="small-img logo-image" src="assets/<?php echo $nightMode ? 'light/' : ''; ?>favicon.png" alt="logo image"/>
            </span>
            <button type="button" class="user-btn" id="profileBtn">
                <img src="assets/<?php echo $nightMode ? 'light/' : ''; ?>user.png" alt="user image" id="userImage">
            </button>
            <button type="button" class="menu-btn" id="menuBtn">
                <img src="assets/<?php echo $nightMode ? 'light/dots.png' : 'dots.png'; ?>" alt="nav icon"/>
            </button>
            
            <!-- Profile Panel -->
            <div class="profile-page hidden" id="profilePage">
                <div class="profile-info">
                    <h2><?php echo htmlspecialchars($userName); ?></h2>
                    <span><?php echo htmlspecialchars($userEmail); ?></span>
                </div>
                <div class="night-mode">
                    <img src="assets/<?php echo $nightMode ? 'light/' : ''; ?>moon.png" alt="moon icon" id="nightImage">
                    Night Mode
                    <button type="button" id="nightModeBtn">
                        <span id="toggleNightMode" class="<?php echo $nightMode ? 'turned-on' : ''; ?>"></span>
                    </button>
                </div>
                <button type="button" id="logoutAcc">
                    <img src="assets/<?php echo $nightMode ? 'light/' : ''; ?>logout.png" alt="logout icon" id="logoutImage">
                    Logout
                </button>
            </div>
        </header>
        
        <!-- Main Content -->
        <div class="main-content <?php echo $isLoggedIn ? '' : 'hidden'; ?>" id="mainContent">
            <div id="messageThread" class="thread-holder">
                <div class="thread-header">
                    <div class="thread-title">
                        <span>THREAD TITLE</span>
                        <input type="text" value="Conversation Thread Title" placeholder="Conversation Thread Title" id="threadTitleInput" />
                    </div>
                    <button type="button" class="delete-thread-btn <?php echo $isLoggedIn ? '' : 'hidden'; ?>" id="deleteThreadBtn" title="Delete thread">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                        </svg>
                    </button>
                </div>
                <div class="thread-messages" id="threadMessages">
                    <div class="prelim-template">
                        <img src="assets/circuit.png" alt="empty template image" id="circuitImage"/>
                        <h2>Welcome to SmartSpecs!</h2>
                        <p>Your AI assistant in choosing the best computer setup.</p>
                        <h3>What we do?</h3>
                        <ul>
                            <li>Suggest best compatible parts based on your specific needs.</li>
                            <li>Assists you in making best choices in buying your setup.</li>
                            <li>Generate recommendation for future upgrades.</li>
                        </ul>
                        <h4>Start Now!</h4>
                        <p>Try typing: <em>"Provide me a specs for a computer. My budget is 20,000 pesos."</em></p>
                    </div>
                </div>
                <div class="thread-input" id="threadInput">
                    <span>
                        <textarea id="textInput">
                            <?php echo $isLoggedIn ? '' : 'Please login to send messages'; ?>
                        </textarea>
                        <button type="button" id="sendButton" <?php echo $isLoggedIn ? '' : 'disabled'; ?>>
                            <img src="assets/<?php echo $nightMode ? 'light/' : ''; ?>send.png" alt="send text image" id="sendImage"/>
                        </button>
                    </span>
                </div>
            </div>
            
            <!-- Side Navigation -->
            <div id="sideNav" class="side-nav">
                <button class="nav-btn new-convo" type="button" id="newConvoBtn" <?php echo $isLoggedIn ? '' : 'disabled'; ?>>
                    <img src="assets/<?php echo $nightMode ? 'light/' : ''; ?>new-message.png" alt="new message icon" id="newThreadImage">
                    New Conversation
                </button>
                <span>THREADS</span>
                <div class="convo-holder" id="convoHolder">
                    <?php if ($isLoggedIn && !empty($userThreads)): ?>
                        <?php foreach ($userThreads as $thread): ?>
                            <button class="nav-btn thread-btn" type="button" data-thread-id="<?php echo $thread['id']; ?>">
                                <?php echo htmlspecialchars($thread['title']); ?>
                            </button>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <button class="nav-btn" type="button" disabled>No conversations yet</button>
                    <?php endif; ?>
                </div>
                <button class="nav-btn info-btn" type="button">
                    <img src="assets/<?php echo $nightMode ? 'light/' : ''; ?>about.png" alt="about icon" id="aboutImage">
                </button>
            </div>
        </div>
    </main>
    
    <script>
    // Pass PHP variables to JavaScript
    const PHP_CONFIG = {
        isLoggedIn: <?php echo $isLoggedIn ? 'true' : 'false'; ?>,
        userId: <?php echo $isLoggedIn ? $_SESSION['user_id'] : 'null'; ?>,
        userEmail: '<?php echo $userEmail; ?>',
        userName: '<?php echo $userName; ?>',
        nightMode: <?php echo $nightMode ? 'true' : 'false'; ?>,
        baseUrl: '<?php echo getBaseUrl(); ?>'
    };
    </script>
    <script src="script.js" defer></script>
</body>
</html>