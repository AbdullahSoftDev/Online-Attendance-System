<?php

class EmployeePortal {
    private $mysql;
    private $calendar;
    
    public function __construct() {
        $this->mysql = new MySQLConfig();
        $this->calendar = new CalendarSystem();
    }
    
    public function display_employee_interface() {
    ob_start();
    
    if (isset($_GET['test_db'])) {
        echo '<div style="background: #fff3cd; padding: 15px; margin: 15px 0; border-radius: 5px;">';
        echo '<h4>MySQL Database Test:</h4>';
        echo '<p>' . $this->mysql->test_connection() . '</p>';
        echo '</div>';
    }
    
    // 🚨 CRITICAL FIX: Handle password reset FIRST with multiple checks
    if (isset($_POST['reset_password']) || 
        (isset($_POST['reset_password_form']) && $_POST['reset_password_form'] == '1') ||
        (isset($_POST['action']) && $_POST['action'] === 'reset_password')) {
        
        error_log("🔄 PASSWORD RESET DETECTED - Processing...");
        $this->handle_password_reset();
    } elseif (isset($_POST['employee_login'])) {
        $this->handle_employee_login();
    } elseif (isset($_POST['check_in'])) {
        $this->handle_check_in();
    } elseif (isset($_POST['check_out'])) {
        $this->handle_check_out();
    } elseif (isset($_GET['action']) && $_GET['action'] === 'logout') {
        $this->logout();
    }
    
    if (isset($_SESSION['employee_logged_in']) && $_SESSION['employee_logged_in']) {
        $this->display_attendance_actions();
    } else {
        $this->display_login_form();
    }
    
    return ob_get_clean();
}
    
    private function display_login_form() {
        $show_reset_form = isset($_GET['action']) && $_GET['action'] === 'reset_password';
        ?>
        <div class="employee-login-portal">
            <div class="employee-login-header">
                <h2>Employee Portal</h2>
                <p class="subtitle">Sharpcode Attendance System</p>
                <div class="employee-login-badge">
                    ✅ Using WordPress MySQL Database
                </div>
            </div>
            
            <?php if (!$show_reset_form): ?>
            <div class="employee-login-form">
                <div class="recovery-info">
                    <p><strong>🔧 Please Enter Your Credentials</strong></p>
                </div>
                
                <form method="post">
                    <div class="employee-form-group">
                        <label for="employee_id">EMPLOYEE ID:</label>
                        <input type="text" id="employee_id" name="employee_id" required 
                               class="employee-form-input"
                               placeholder="Enter your Employee ID"
                               value="<?php echo isset($_POST['employee_id']) ? esc_attr($_POST['employee_id']) : ''; ?>">
                    </div>
                    
                    <div class="employee-form-group">
                        <label for="employee_password">PASSWORD:</label>
                        <input type="password" id="employee_password" name="employee_password" required 
                               class="employee-form-input"
                               placeholder="Enter your password">
                    </div>
                    
                    <button type="submit" name="employee_login" class="employee-login-btn">
                        🔐 Login to System
                    </button>
                </form>
                
                <div class="employee-login-actions">
                    <a href="?action=reset_password" class="employee-recovery-link">
                        🔑 Forget Password?
                    </a>
                </div>
                
                <div class="employee-login-features">
                    <div class="employee-feature">
                        <span class="employee-feature-icon">⏰</span>
                        <div class="employee-feature-text">Easy Check-in/out</div>
                    </div>
                    <div class="employee-feature">
                        <span class="employee-feature-icon">📊</span>
                        <div class="employee-feature-text">Attendance Tracking</div>
                    </div>
                    <div class="employee-feature">
                        <span class="employee-feature-icon">🔒</span>
                        <div class="employee-feature-text">Secure Login</div>
                    </div>
                    <div class="employee-feature">
                        <span class="employee-feature-icon">📱</span>
                        <div class="employee-feature-text">Mobile Friendly</div>
                    </div>
                </div>
            </div>
            
<?php else: ?>

<div class="employee-reset-form" id="passwordResetContainer">
    <div class="employee-reset-header">
        <h3>🔑 Reset Your Password</h3>
        <p class="employee-reset-description">Please enter your Employee ID and previous password to reset your password.</p>
    </div>
    
    <form method="post" id="passwordResetForm" class="reset-form-active">
        <input type="hidden" name="reset_password_form" value="1">
        
        <div class="employee-form-group">
            <label for="reset_employee_id">EMPLOYEE ID:</label>
            <input type="text" id="reset_employee_id" name="reset_employee_id" required 
                   class="employee-form-input reset-input"
                   placeholder="Enter your Employee ID"
                   value="<?php echo isset($_POST['reset_employee_id']) ? esc_attr($_POST['reset_employee_id']) : ''; ?>"
                   autocomplete="off">
        </div>
        
        <div class="employee-form-group">
            <label for="old_password">PREVIOUS PASSWORD:</label>
            <input type="password" id="old_password" name="old_password" required 
                   class="employee-form-input reset-input"
                   placeholder="Enter your previous password"
                   autocomplete="off">
        </div>
        
        <div class="employee-form-group">
            <label for="new_password">NEW PASSWORD:</label>
            <input type="password" id="new_password" name="new_password" required 
                   class="employee-form-input reset-input"
                   placeholder="Enter new password (min 6 characters)"
                   autocomplete="new-password">
        </div>
        
        <div class="employee-form-group">
            <label for="confirm_password">CONFIRM NEW PASSWORD:</label>
            <input type="password" id="confirm_password" name="confirm_password" required 
                   class="employee-form-input reset-input"
                   placeholder="Confirm new password"
                   autocomplete="new-password">
        </div>
        
        <button type="submit" name="reset_password" class="employee-login-btn reset-submit-btn" id="resetPasswordBtn">
            🔄 Reset Password
        </button>
    </form>
    
    <div class="employee-back-link">
        <a href="?">↩ Back to Login</a>
    </div>
</div>

<style>
/* Keep your original beautiful styles but add interactivity fixes */
.employee-reset-form {
    background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
    padding: 40px;
    border-radius: 20px;
    border: 1px solid #e1e8ed;
    box-shadow: 0 20px 60px rgba(0,0,0,0.1);
    margin: 20px 0;
    position: relative;
    z-index: 100;
}

.employee-reset-header {
    text-align: center;
    margin-bottom: 30px;
}

.employee-reset-header h3 {
    color: #2c3e50;
    font-size: 28px;
    font-weight: 700;
    margin-bottom: 10px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
}

.employee-reset-description {
    color: #7f8c8d;
    font-size: 16px;
    line-height: 1.5;
}

.employee-form-group {
    margin-bottom: 25px;
}

.employee-form-group label {
    display: block;
    margin-bottom: 8px;
    font-weight: 600;
    color: #2c3e50;
    font-size: 14px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.employee-form-input {
    width: 100%;
    padding: 15px 20px;
    border: 2px solid #e1e8ed;
    border-radius: 12px;
    font-size: 16px;
    transition: all 0.3s ease;
    background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
    box-sizing: border-box;
    color: #2c3e50;
}

.employee-form-input:focus {
    border-color: #667eea;
    background: white;
    box-shadow: 0 0 0 4px rgba(102, 126, 234, 0.1);
    outline: none;
    transform: translateY(-2px);
}

.employee-form-input:hover {
    border-color: #a5b1c2;
    background: white;
    transform: translateY(-1px);
}

.employee-form-input::placeholder {
    color: #a5b1c2;
}

.employee-login-btn {
    width: 100%;
    padding: 18px;
    border: none;
    border-radius: 12px;
    font-size: 18px;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.3s ease;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    box-shadow: 0 8px 25px rgba(102, 126, 234, 0.3);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.employee-login-btn:hover {
    background: linear-gradient(135deg, #5a6fd8 0%, #6b3fa0 100%);
    transform: translateY(-3px);
    box-shadow: 0 12px 35px rgba(102, 126, 234, 0.4);
}

.employee-back-link {
    text-align: center;
    margin-top: 25px;
}

.employee-back-link a {
    color: #667eea;
    text-decoration: none;
    font-weight: 600;
    transition: all 0.3s ease;
    padding: 10px 20px;
    border-radius: 6px;
    background: rgba(102, 126, 234, 0.1);
}

.employee-back-link a:hover {
    color: #5a6fd8;
    text-decoration: none;
    background: rgba(102, 126, 234, 0.2);
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(102, 126, 234, 0.2);
}

/* CRITICAL: Add only the necessary fixes for interactivity */
#passwordResetContainer {
    pointer-events: auto !important;
    position: relative !important;
    z-index: 100 !important;
}

#passwordResetForm {
    pointer-events: auto !important;
    position: relative !important;
    z-index: 100 !important;
}

.reset-form-active input,
.reset-form-active button,
.reset-form-active select,
.reset-form-active textarea {
    pointer-events: auto !important;
    opacity: 1 !important;
    position: relative !important;
    z-index: 100 !important;
}

/* Remove any potential overlays blocking interaction */
.employee-reset-form::before,
.employee-reset-form::after {
    display: none !important;
    pointer-events: none !important;
}

/* Ensure form elements are always interactive */
.reset-input {
    pointer-events: auto !important;
}

.reset-submit-btn {
    pointer-events: auto !important;
    cursor: pointer !important;
}

/* Emergency override for any WordPress CSS conflicts */
.employee-reset-form * {
    pointer-events: auto !important;
}

.employee-reset-form input:disabled,
.employee-reset-form button:disabled {
    pointer-events: none !important;
    opacity: 0.6 !important;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    console.log('🔧 Password reset form initialization started');
    
    const resetForm = document.getElementById('passwordResetForm');
    const resetBtn = document.getElementById('resetPasswordBtn');
    const inputs = document.querySelectorAll('.reset-input');
    const container = document.getElementById('passwordResetContainer');
    
    console.log('Form found:', !!resetForm);
    console.log('Button found:', !!resetBtn);
    console.log('Inputs found:', inputs.length);
    console.log('Container found:', !!container);
    
    // Remove any blocking styles and ensure interactivity
    function enableFormInteractivity() {
        // Enable all form elements
        if (resetForm) {
            const allElements = resetForm.querySelectorAll('*');
            allElements.forEach(element => {
                element.style.pointerEvents = 'auto';
                element.style.opacity = '1';
                element.style.visibility = 'visible';
                element.disabled = false;
                element.readOnly = false;
                
                // Remove any inline styles that might block interaction
                if (element.style.pointerEvents === 'none') {
                    element.style.pointerEvents = 'auto';
                }
                if (element.style.opacity === '0') {
                    element.style.opacity = '1';
                }
            });
        }
        
        // Remove any overlays that might be blocking
        document.querySelectorAll('.overlay, .modal-backdrop').forEach(el => {
            if (el.closest('.employee-reset-form')) {
                el.remove();
            }
        });
    }
    
    // Run the fix immediately
    enableFormInteractivity();
    
    // Add interactive event listeners
    if (resetForm) {
        inputs.forEach(input => {
            input.addEventListener('focus', function() {
                console.log('📝 Input focused:', this.name);
                this.style.borderColor = '#667eea';
                this.style.transform = 'translateY(-2px)';
            });
            
            input.addEventListener('blur', function() {
                console.log('📝 Input blurred:', this.name);
                this.style.borderColor = '#e1e8ed';
                this.style.transform = 'translateY(0)';
            });
            
            input.addEventListener('input', function() {
                console.log('📝 Input changed:', this.name, this.value.length + ' characters');
            });
        });
        
        // Button functionality
        if (resetBtn) {
            resetBtn.addEventListener('click', function(e) {
                console.log('🔄 Reset button clicked!');
                // Form will submit normally
            });
            
            resetBtn.addEventListener('mouseenter', function() {
                this.style.transform = 'translateY(-3px)';
            });
            
            resetBtn.addEventListener('mouseleave', function() {
                this.style.transform = 'translateY(0)';
            });
        }
        
        // Form submission
        resetForm.addEventListener('submit', function(e) {
            console.log('🚀 Form submission started!');
            // Add any validation here if needed
        });
    }
    
    // Final safety check - run after a short delay
    setTimeout(enableFormInteractivity, 100);
    setTimeout(enableFormInteractivity, 500);
});

// Additional safety function that can be called if issues persist
function forceEnablePasswordForm() {
    const form = document.getElementById('passwordResetForm');
    if (form) {
        console.log('🛠️ Force-enabling password form...');
        
        // Clone and replace to remove any attached event listeners
        const newForm = form.cloneNode(true);
        form.parentNode.replaceChild(newForm, form);
        
        // Re-apply styles and functionality
        setTimeout(() => {
            document.querySelectorAll('.reset-input').forEach(input => {
                input.style.pointerEvents = 'auto';
                input.disabled = false;
                input.readOnly = false;
            });
        }, 50);
    }
}

// Run emergency fix after page load
window.addEventListener('load', function() {
    setTimeout(forceEnablePasswordForm, 1000);
});
</script>

<?php endif; ?>
            
            <div style="text-align: center; margin-top: 30px; padding: 20px; border-top: 1px solid #e1e8ed;">
                <p style="color: #7f8c8d; font-size: 12px; margin: 0;">
                    Designed by Elegant Themes | Powered by WordPress
                </p>
            </div>
        </div>
        <?php
    }
    
    private function handle_employee_login() {
        $employee_id = sanitize_text_field($_POST['employee_id']);
        $password = sanitize_text_field($_POST['employee_password']);
        
        $employee = $this->mysql->verify_employee($employee_id, $password);
        
        if ($employee) {
            $_SESSION['employee_logged_in'] = true;
            $_SESSION['employee_id'] = $employee_id;
            $_SESSION['employee_name'] = $employee['name'];
            $_SESSION['scheduled_time'] = $employee['scheduled_time'];
            echo '<div class="employee-success">
                    ✅ SUCCESS! Logged in as: ' . esc_html($employee['name']) . '
                  </div>';
        } else {
            echo '<div class="employee-error">
                    ❌ Invalid employee ID or password!
                  </div>';
        }
    }
    
    private function handle_password_reset() {
    // 🚨 CRITICAL FIX: Use the correct field names from the form
    $employee_id = isset($_POST['reset_employee_id']) ? sanitize_text_field($_POST['reset_employee_id']) : '';
    $old_password = isset($_POST['old_password']) ? sanitize_text_field($_POST['old_password']) : '';
    $new_password = isset($_POST['new_password']) ? sanitize_text_field($_POST['new_password']) : '';
    $confirm_password = isset($_POST['confirm_password']) ? sanitize_text_field($_POST['confirm_password']) : '';
    
    error_log("🔄 PASSWORD RESET ATTEMPT: Employee: $employee_id, Old: $old_password, New: $new_password, Confirm: $confirm_password");
    
    // Validation
    if (empty($employee_id) || empty($old_password) || empty($new_password) || empty($confirm_password)) {
        echo '<div class="employee-error">
                ❌ All fields are required!
              </div>';
        return;
    }
    
    if ($new_password !== $confirm_password) {
        echo '<div class="employee-error">
                ❌ New passwords do not match!
              </div>';
        return;
    }
    
    if (strlen($new_password) < 6) {
        echo '<div class="employee-error">
                ❌ New password must be at least 6 characters long!
              </div>';
        return;
    }
    
    // Verify old password
    $employee = $this->mysql->verify_employee($employee_id, $old_password);
    if (!$employee) {
        echo '<div class="employee-error">
                ❌ Invalid Employee ID or previous password!
              </div>';
        return;
    }
    
    // Check if new password is same as old password
    if ($old_password === $new_password) {
        echo '<div class="employee-error">
                ❌ New password cannot be the same as old password!
              </div>';
        return;
    }
    
    // Check if new password was used before
    if ($this->mysql->is_previous_password($employee_id, $new_password)) {
        echo '<div class="employee-error">
                ❌ This password was used before. Please choose a different password!
              </div>';
        return;
    }
    
    // Update password
    $result = $this->mysql->update_employee_password($employee_id, $new_password);
    
    if (isset($result['success'])) {
        echo '<div class="employee-success">
                ✅ Password reset successfully! You can now login with your new password.
              </div>';
        echo '<script>setTimeout(function(){ window.location.href = "?"; }, 2000);</script>';
    } else {
        echo '<div class="employee-error">
                ❌ Error: ' . esc_html($result['error']) . '
              </div>';
    }
}
    
    public function debug_calendar_status() {
        $date = current_time('Y-m-d');
        $is_working = $this->calendar->is_working_day($date);
        
        echo '<div style="background: #ffeb3b; padding: 10px; margin: 10px 0; border-radius: 5px;">';
        echo '<strong>🔍 CALENDAR DEBUG:</strong><br>';
        echo 'Today: ' . $date . '<br>';
        echo 'Is Working Day: ' . ($is_working ? '✅ YES' : '❌ NO') . '<br>';
        echo 'Check your error_log for detailed debug info';
        echo '</div>';
    }
    
    private function display_attendance_actions() {
        $current_time = current_time('Y-m-d H:i:s');
        $today = current_time('Y-m-d');
        $today_record = $this->mysql->get_today_attendance($_SESSION['employee_id'], $today);
        $has_checked_in = !empty($today_record) && !empty($today_record['in_time']);
        $has_checked_out = !empty($today_record) && !empty($today_record['out_time']);
        $scheduled_time = $_SESSION['scheduled_time'];
        $this->debug_calendar_status();
        
        // Calculate if currently late
        $current_timestamp = strtotime($current_time);
        $scheduled_timestamp = strtotime($today . ' ' . $scheduled_time);
        $late_minutes = max(0, ($current_timestamp - $scheduled_timestamp) / 60);
        $is_late = $late_minutes > 0;
        
        // 🚨 HOLIDAY CHECK - Check if today is a holiday
        $is_holiday = !$this->calendar->is_working_day($today);
        $checkin_disabled = $has_checked_in || $is_holiday;
        $checkin_message = $has_checked_in ? '✅ Already Checked In' : 
                          ($is_holiday ? '❌ Holiday - No Check-in' : '📍 Check In Now');
        ?>
        <div class="employee-portal">
            <div class="portal-header">
                <h2>Welcome, <?php echo esc_html($_SESSION['employee_name']); ?>! 👋</h2>
                <div class="employee-info">
                    <span>ID: <?php echo esc_html($_SESSION['employee_id']); ?></span>
                    <span class="scheduled-time">⏰ Scheduled: <?php echo esc_html($scheduled_time); ?></span>
                    <a href="?action=logout" class="logout-btn">🚪 Logout</a>
                </div>
            </div>
            
            <!-- 🎉 Holiday Notification -->
            <?php if ($is_holiday): ?>
            <div class="employee-holiday" style="background: linear-gradient(135deg, #28a745 0%, #20c997 100%); color: white; padding: 20px; border-radius: 12px; margin: 20px 0; text-align: center; border: 2px solid #1e7e34; box-shadow: 0 6px 20px rgba(40, 167, 69, 0.3); transition: all 0.3s cubic-bezier(0.25, 0.46, 0.45, 0.94); cursor: pointer;">
                <h3 style="margin: 0 0 12px 0; font-size: 24px; color: white; text-shadow: 0 2px 4px rgba(0,0,0,0.2);">🎉 Holiday Today!</h3>
                <p style="margin: 0; font-size: 16px; line-height: 1.5; color: white; font-weight: 500;">
                    <strong style="color: white;">Enjoy your day off!</strong><br>
                    Attendance marking is disabled for today.
                </p>
            </div>

            <style>
            .employee-holiday:hover {
                transform: translateY(-5px) scale(1.02);
                box-shadow: 0 12px 30px rgba(40, 167, 69, 0.4);
                border-color: #155724;
            }
            </style>
            <?php endif; ?>
            
            <!-- Late Warning -->
            <?php if (!$has_checked_in && $is_late && !$is_holiday): ?>
            <div class="employee-warning">
                ⚠️ <strong>You are currently <?php echo floor($late_minutes); ?> minutes late!</strong>
                <?php if ($late_minutes >= 180): ?>
                    <br>❌ <strong>You will be marked as ABSENT if you check in now!</strong>
                <?php elseif ($late_minutes >= 120): ?>
                    <br>⏰ <strong>You will be marked as LATE if you check in now!</strong>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            
            <div class="attendance-status">
                <h3>📊 Today's Attendance Status</h3>
                <div class="status-cards">
                    <div class="status-card scheduled">
                        <h4>Scheduled Time</h4>
                        <p class="status-time">⏰ <?php echo esc_html($scheduled_time); ?></p>
                    </div>
                    <div class="status-card <?php echo $has_checked_in ? 'completed' : 'pending'; ?>">
                        <h4>Check-in</h4>
                        <p class="status-time">
                            <?php 
                            if ($is_holiday && !$has_checked_in) {
                                echo '🎉 Holiday';
                            } else {
                                echo $has_checked_in ? '✅ ' . esc_html(date('H:i:s', strtotime($today_record['in_time']))) : '❌ Not Checked In';
                            }
                            ?>
                        </p>
                        <?php if ($has_checked_in && $today_record['late_status'] === 'late'): ?>
                        <p class="late-notice">
                            ⏰ Late by <?php echo $today_record['late_minutes']; ?> minutes
                        </p>
                        <?php elseif ($has_checked_in && $today_record['late_status'] === 'absent'): ?>
                        <p class="absent-notice">
                            ❌ Marked as ABSENT
                        </p>
                        <?php endif; ?>
                    </div>
                    <div class="status-card <?php echo $has_checked_out ? 'completed' : 'pending'; ?>">
                        <h4>Check-out</h4>
                        <p class="status-time">
                            <?php 
                            if ($is_holiday && !$has_checked_out) {
                                echo '🎉 Holiday';
                            } else {
                                echo $has_checked_out ? '✅ ' . esc_html(date('H:i:s', strtotime($today_record['out_time']))) : '❌ Not Checked Out';
                            }
                            ?>
                        </p>
                    </div>
                </div>
                
                <!-- Approval Status -->
                <?php if ($has_checked_in): ?>
                <div class="approval-status">
                    <strong>Approval Status:</strong>
                    <?php
                    $status_badge = '';
                    switch($today_record['status']) {
                        case 'approved':
                            $status_badge = '<span class="approval-badge approved">✅ Approved</span>';
                            break;
                        case 'rejected':
                            $status_badge = '<span class="approval-badge rejected">❌ Rejected</span>';
                            break;
                        default:
                            $status_badge = '<span class="approval-badge pending">⏳ Pending Approval</span>';
                    }
                    echo $status_badge;
                    ?>
                </div>
                <?php endif; ?>
            </div>
            
            <div class="attendance-actions">
                <div class="action-card">
                    <h4>📍 Check In</h4>
                    <div class="current-time">
                        <strong>Current System Time:</strong>
                        <span id="current-time-in"><?php echo esc_html($current_time); ?></span>
                    </div>
                    <form method="post">
                        <input type="hidden" name="check_in_time" value="<?php echo esc_attr($current_time); ?>">
                        <button type="submit" name="check_in" class="check-in-btn" <?php echo $checkin_disabled ? 'disabled' : ''; ?> style="<?php echo $is_holiday ? 'background: #95a5a6 !important; cursor: not-allowed !important; opacity: 0.6;' : ''; ?>">
                            <?php echo $checkin_message; ?>
                        </button>
                    </form>
                    <?php if (!$has_checked_in): ?>
                    <p class="action-note">
                        <?php 
                        if ($is_holiday) {
                            echo '🎉 Today is a holiday! Enjoy your day off.';
                        } elseif ($is_late) {
                            if ($late_minutes >= 180) {
                                echo '⚠️ You will be marked as ABSENT';
                            } elseif ($late_minutes >= 120) {
                                echo '⚠️ You will be marked as LATE';
                            } else {
                                echo '⚠️ You are running late';
                            }
                        } else {
                            echo 'Check in when you arrive';
                        }
                        ?>
                    </p>
                    <?php endif; ?>
                </div>
                
                <div class="action-card">
                    <h4>🏁 Check Out</h4>
                    <div class="current-time">
                        <strong>Current System Time:</strong>
                        <span id="current-time-out"><?php echo esc_html($current_time); ?></span>
                    </div>
                    <form method="post">
                        <input type="hidden" name="check_out_time" value="<?php echo esc_attr($current_time); ?>">
                        <button type="submit" name="check_out" class="check-out-btn" <?php echo ($has_checked_out || !$has_checked_in || $is_holiday) ? 'disabled' : ''; ?> style="<?php echo $is_holiday ? 'background: #95a5a6 !important; cursor: not-allowed !important; opacity: 0.6;' : ''; ?>">
                            <?php 
                            if ($is_holiday) {
                                echo '❌ Holiday - No Check-out';
                            } elseif ($has_checked_out) {
                                echo '✅ Already Checked Out';
                            } elseif (!$has_checked_in) {
                                echo '⚠ Check In First';
                            } else {
                                echo '🏁 Check Out Now';
                            }
                            ?>
                        </button>
                    </form>
                    <?php if ($has_checked_in && !$has_checked_out && !$is_holiday): ?>
                    <p class="action-note">Check out when you leave for the day</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <script>
            function updateTime() {
                var now = new Date();
                var timeString = now.getFullYear() + '-' + 
                               String(now.getMonth() + 1).padStart(2, '0') + '-' + 
                               String(now.getDate()).padStart(2, '0') + ' ' + 
                               String(now.getHours()).padStart(2, '0') + ':' + 
                               String(now.getMinutes()).padStart(2, '0') + ':' + 
                               String(now.getSeconds()).padStart(2, '0');
                
                document.getElementById('current-time-in').textContent = timeString;
                document.getElementById('current-time-out').textContent = timeString;
                
                document.querySelector('input[name="check_in_time"]').value = timeString;
                document.querySelector('input[name="check_out_time"]').value = timeString;
            }
            
            setInterval(updateTime, 1000);
        </script>
        <?php
    }
    
    private function handle_check_in() {
        // Check if employee is logged in
        if (!isset($_SESSION['employee_logged_in']) || !$_SESSION['employee_logged_in']) {
            echo '<div class="employee-error">
                    ❌ You must be logged in to check in!
                  </div>';
            return;
        }
        
        $check_in_time = sanitize_text_field($_POST['check_in_time']);
        $employee_id = $_SESSION['employee_id'];
        $date = current_time('Y-m-d');
        $scheduled_time = $_SESSION['scheduled_time'];

        // 🚨 STRICT HOLIDAY CHECK with detailed logging
        error_log("🔍 CHECK-IN ATTEMPT: Date: $date, Employee: $employee_id");
        
        $is_working_day = $this->calendar->is_working_day($date);
        error_log("📅 HOLIDAY CHECK RESULT: is_working_day() = " . ($is_working_day ? 'TRUE' : 'FALSE'));
        
        if (!$is_working_day) {
            echo '<div class="employee-error">
                    ❌ Today is a holiday! Attendance cannot be marked.
                  </div>';
            error_log("🚫 HOLIDAY BLOCKED: Employee $employee_id blocked from checking in on holiday $date");
            return;
        }
        
        $existing_record = $this->mysql->get_today_attendance($employee_id, $date);
        
        if ($existing_record && !empty($existing_record['in_time'])) {
            echo '<div class="employee-error">
                    ❌ Already checked in today at ' . esc_html($existing_record['in_time']) . '!
                  </div>';
            return;
        }
        
        $data = array(
            'employee_id' => $employee_id,
            'employee_name' => $_SESSION['employee_name'],
            'date' => $date,
            'in_time' => $check_in_time,
            'scheduled_time' => $scheduled_time,
            'created_at' => current_time('Y-m-d H:i:s')
        );
        
        // Calculate late status
        if (!empty($data['in_time']) && !empty($data['scheduled_time'])) {
            $checkin_time = strtotime($data['in_time']);
            $scheduled_time = strtotime($data['date'] . ' ' . $data['scheduled_time']);
            $late_minutes = max(0, ($checkin_time - $scheduled_time) / 60);
            
            $data['late_minutes'] = $late_minutes;
            
            if ($late_minutes >= 180) { // 3 hours = absent
                $data['late_status'] = 'absent';
            } elseif ($late_minutes >= 120) { // 2 hours = late
                $data['late_status'] = 'late';
            } else {
                $data['late_status'] = 'on_time';
            }
        }
        
        $result = $this->mysql->insert_attendance($data);
        
        if (!isset($result['error'])) {
            $record = $this->mysql->get_today_attendance($employee_id, $date);
            $status_message = '✅ Check-in recorded at ' . esc_html($check_in_time);
            
            if ($record && $record['late_status'] === 'late') {
                $status_message .= '<br>⚠️ <strong>You are late by ' . $record['late_minutes'] . ' minutes!</strong>';
            } elseif ($record && $record['late_status'] === 'absent') {
                $status_message .= '<br>❌ <strong>You are marked as ABSENT (3+ hours late)!</strong>';
            }
            
            $status_message .= '<br>📋 <strong>Status:</strong> Pending Admin Approval';
            
            echo '<div class="employee-success">' . $status_message . '</div>';
        } else {
            echo '<div class="employee-error">
                    ❌ Database error: ' . esc_html($result['error']) . '
                  </div>';
        }
    }
    
    private function handle_check_out() {
        $check_out_time = sanitize_text_field($_POST['check_out_time']);
        $employee_id = $_SESSION['employee_id'];
        $date = current_time('Y-m-d');
        
        $today_record = $this->mysql->get_today_attendance($employee_id, $date);
        
        if (!$today_record) {
            echo '<div class="employee-error">
                    ❌ No check-in found for today. Please check in first.
                  </div>';
            return;
        }
        
        // Check if already checked out
        if (!empty($today_record['out_time'])) {
            echo '<div class="employee-error">
                    ❌ Already checked out today at ' . esc_html($today_record['out_time']) . '!
                  </div>';
            return;
        }
        
        // Verify we have a valid check-in time
        if (empty($today_record['in_time'])) {
            echo '<div class="employee-error">
                    ❌ Invalid check-in record found. Please contact administrator.
                  </div>';
            return;
        }
        
        $data = array(
            'out_time' => $check_out_time,
            'updated_at' => current_time('Y-m-d H:i:s')
        );
        
        $result = $this->mysql->update_attendance_record($today_record['id'], $data);
        
        if (!isset($result['error'])) {
            echo '<div class="employee-success">
                    ✅ Check-out recorded successfully at ' . esc_html($check_out_time) . '!
                  </div>';
        } else {
            echo '<div class="employee-error">
                    ❌ Database error: ' . esc_html($result['error']) . '
                  </div>';
        }
    }
    
    private function logout() {
        session_destroy();
        echo '<div class="employee-success">
                ✅ Logged out successfully. Redirecting...
              </div>';
        echo '<script>setTimeout(function(){ window.location.href = window.location.pathname; }, 1000);</script>';
    }
}
?>