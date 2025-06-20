<?php
session_start();
include 'db_connect.php';  

function safe_session($key, $array) {
    return htmlspecialchars($array[$key] ?? '');
}

function generateUniqueTripID($conn) {
    do {
        $trip_id = str_pad(rand(0, 9999), 4, '0', STR_PAD_LEFT);
        
        $stmt = $conn->prepare("SELECT COUNT(*) AS count FROM booking WHERE bookingid = ?");
        if (!$stmt) {
            die("Prepare failed: (" . $conn->errno . ") " . $conn->error);
        }
        $stmt->bind_param("s", $trip_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : ['count' => 0];
        $stmt->close();
    } while ($row['count'] > 0);
    
    return $trip_id;
}

$trip      = $_SESSION['trip'] ?? [];
$passenger = $_SESSION['passenger'] ?? [];

$showError = false;
$error = '';
if (isset($_SESSION['payment_error'])) {
    $error = $_SESSION['payment_error'];
    $showError = true;
    unset($_SESSION['payment_error']);
}

$origin      = strtoupper(safe_session('origin', $trip));
$destination = strtoupper(safe_session('destination', $trip));
$depart      = safe_session('depart', $trip);
$tripType    = ucwords(str_replace('-', ' ', safe_session('trip_type', $trip)));
$passengers  = intval(safe_session('passengers', $trip));

// Retrieve schedule-specific details.
$selectedTime  = safe_session('time', $trip);
$selectedClass = safe_session('class', $trip);

// Determine fare by querying the DB.
$routeid    = null;
$bustypeid  = null;
$fareamount = 0;

// 1. Get route ID.
$stmt = $conn->prepare("SELECT routeid FROM routes WHERE origin = ? AND destination = ? LIMIT 1");
if ($stmt) {
    $stmt->bind_param("ss", $origin, $destination);
    $stmt->execute();
    $stmt->bind_result($routeid);
    $stmt->fetch();
    $stmt->close();
}

// 2. Get bus type ID.
$stmt = $conn->prepare("SELECT bustypeid FROM bustype WHERE description = ? LIMIT 1");
if ($stmt) {
    $stmt->bind_param("s", $selectedClass);
    $stmt->execute();
    $stmt->bind_result($bustypeid);
    $stmt->fetch();
    $stmt->close();
}

// 3. Look up fare.
$stmt = $conn->prepare("SELECT fareid, fareamount FROM farematrix WHERE routeid = ? AND bustypeid = ? LIMIT 1");
if ($stmt) {
    $stmt->bind_param("ii", $routeid, $bustypeid);
    $stmt->execute();
    $stmt->bind_result($fareid, $fareamount);
    if (!$stmt->fetch()) {
        // Handle case: no matching fare found.
        die("Error: No fare found for this route and bus type.");
    }
    $_SESSION['trip']['fareid'] = $fareid;
    $stmt->close();
}
$selectedFare = floatval($fareamount);

// Retrieve passenger details.
$firstName   = safe_session('firstName', $passenger);
$middleName  = safe_session('middleName', $passenger);
$lastName    = safe_session('lastName', $passenger);
$email       = safe_session('email', $passenger);
$mobileNo    = safe_session('mobileNo', $passenger);
$fullAddress = safe_session('fullAddress', $passenger);

// Discount selection (stored as discount type id).
$discount = safe_session('discount', $passenger);

// Look up the discount rate and label.
$discount_rate = 0;
$discountLabel = 'No discount';
if (!empty($discount)) {
    $stmt = $conn->prepare("SELECT discountrate, description FROM discounttype WHERE discounttypeid = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("s", $discount);
        $stmt->execute();
        $stmt->bind_result($fetched_rate, $fetched_description);
        if ($stmt->fetch()) {
            $discount_rate = floatval($fetched_rate);
            $discountLabel = $fetched_description;
        }
        $stmt->close();
    }
}

// Calculate amounts.
$totalFare       = $selectedFare * $passengers;         // Base fare.
$discount_amount = $totalFare * $discount_rate;           // Discount amount.
$final_amount    = $totalFare - $discount_amount;          // Final payable amount.
$grandtotal      = $final_amount;                         // For invoice, the grand total.

// Generate a unique booking ID.
$trip_id = generateUniqueTripID($conn);

// Process payment submission.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $payment = floatval($_POST['payment'] ?? 0);
    if (abs($payment - $final_amount) > 0.01) {
        $_SESSION['payment_error'] = "Enter the correct amount: ₱" . number_format($final_amount, 2);
        header("Location: payment.php");
        exit;
    } else {
        // Save final payment and booking info in session.
        $_SESSION['final_payment'] = $payment;
        $_SESSION['trip_id'] = $trip_id;  // Save the unique booking ID.
        $_SESSION['totalFare'] = $final_amount;
        $_SESSION['fare_details'] = [
            'selectedFare'    => $selectedFare,
            'totalFare'       => $totalFare,
            'discount_rate'   => $discount_rate,
            'discountLabel'   => $discountLabel,
            'discount_amount' => $discount_amount,
            'final_amount'    => $final_amount
        ];
        
        // Retrieve fareid. (Make sure your bookingSelection page or query stored this value in the trip session.)
        $fareid = safe_session('fareid', $trip); // Adjust if needed.
        $discount_type_id = $discount;  // Already retrieved earlier.
        
        // Current date and time.
        $issueddate  = date('Y-m-d');
        $issuedtime  = date('H:i:s');
        $paymentdate = $issueddate;
        $paymenttime = $issuedtime;
        
        
        // Save complete invoice details in session.
        $_SESSION['invoice'] = [
            'bookingid'      => $trip_id,
            'fareid'         => $fareid,  // Now holds the valid fareid from farematrix.
            'discounttypeid' => $discount_type_id,
            'discountamount' => $discount_amount,
            'grandtotal'     => $grandtotal,
            'issueddate'     => $issueddate,
            'issuedtime'     => $issuedtime,
            'paymentdate'    => $paymentdate,
            'paymenttime'    => $paymenttime
        ];
        
        // Redirect to the receipt page.
        header("Location: receipt.php");
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Busket List - Payment</title>
    <link rel="stylesheet" href="styling/style3.css?v=<?php echo time(); ?>">
    <style>
        html, body {
            height: auto;
            min-height: 100%;
            overflow-x: hidden;
        }
        nav ul li a {
            text-decoration: none;
            color: inherit;
        }
        nav ul li a:hover {
            color: #555;
        }
        .error-message {
            color: red;
            font-weight: bold;
            margin: 0 0 10px 0;
            display: <?php echo $showError ? 'block' : 'none'; ?>;
        }
        .payment-input input[type="number"] {
            width: 100%;
            padding: 8px;
            margin-top: 8px;
            box-sizing: border-box;
        }
    </style>
</head>
<body>
<header>
    <nav>
        <h1>Busket List</h1>
        <ul>
            <li><a href="hero.php">Home</a></li>
            <li><a href="#about-section">About</a></li>
        </ul>
    </nav>
    <div class="img-placeholder"></div>
    <section>
        <div class="book-steps">
            <p><span>Step 4: </span>Payment</p>
        </div>
    </section>
</header>

<main>
    <div class="book-selection">
        <div class="book-header">
            <div class="origin-destination">
                <h1><?php echo $origin; ?> <span class="arrow-separator">►</span> <?php echo $destination; ?></h1>
            </div>
            <div class="book-info">
                <p class="book-details">
                    <?php echo $depart ?: 'N/A'; ?> 
                    <span class="separator">|</span>
                    <?php echo $tripType; ?> 
                    <span class="separator">|</span>
                    Total Passenger: <?php echo $passengers; ?>
                    <?php if (!empty($trip['return'])) echo '<span class="separator">|</span> Return Date: ' . $trip['return']; ?>
                </p>
                <div class="book-details-schedule">
                    <p><span>Departure Time:</span> <?php echo $selectedTime ? date('H:i', strtotime($selectedTime)) : 'N/A'; ?></p>
                    <p><span>Class:</span> <?php echo $selectedClass ?: 'N/A'; ?></p>
                    <p><span>Fare Per Seat:</span> ₱<?php echo number_format($selectedFare, 2); ?></p>
                </div>
            </div>
        </div>
    </div>

    <div class="fare-payment-container">
        <div class="fare-amount-container">
            <div class="fare-amount-title">
                <p>FARE AMOUNT</p>
            </div>
            <div class="fare-amount-info">
                <!-- Base Fare Summary -->
                <div class="fare-amount-summary">
                    <p>Fare amount:</p>
                    <div class="fare-amount-calcu">
                        <p><?php echo $passengers; ?> Passenger(s) x ₱<?php echo number_format($selectedFare, 2); ?></p>
                        <p>₱<?php echo number_format($totalFare, 2); ?></p>
                    </div>
                </div>
                <!-- Discount Summary -->
                <div class="fare-amount-summary">
                    <p>Discount (<?php echo $discountLabel; ?>):</p>
                    <div class="fare-amount-calcu">
                        <?php if ($discount_rate > 0): ?>
                            <p><?php echo $passengers; ?> Passenger(s) x <?php echo ($discount_rate * 100) . '%'; ?> discount</p>
                            <p>-₱<?php echo number_format($discount_amount, 2); ?></p>
                        <?php else: ?>
                            <p>No discount applied</p>
                        <?php endif; ?>
                    </div>
                </div>
                <!-- Final Total -->
                <div class="fare-amount-group">
                    <p>Total amount: </p>
                    <p>₱<?php echo number_format($final_amount, 2); ?></p>
                </div>
            </div>
        </div>

        <form action="" method="POST" class="payment-container">
            <input type="hidden" name="selected_seats_ids" value="<?php echo $selectedSeatsString; ?>">
            <input type="hidden" name="trip_id" value="<?php echo $trip_id; ?>">
            <input type="hidden" name="totalFare" value="<?php echo number_format($final_amount, 2, '.', ''); ?>">
            <div class="payment-input">
                <label for="payment" class="required">Enter payment amount:</label>
                <div class="error-message"><?php echo $error; ?></div>
                <input type="number" step="0.01" id="payment" name="payment" required>
            </div>
            <button type="submit" class="Enter">Pay Now</button>
        </form>
    </div>
</main>
<footer id="about-section">
    <div class="footerBoxes">
        <div class="footerBox">
            <h3>Privacy Policy</h3>
            <hr>
            <p>
            We are committed to protecting your privacy. We will only use the
            information we collect about you lawfully (in accordance with the
            Data Protection Act 1998). Please read on if you wish to learn more
            about our privacy policy.
            </p>
        </div>
        <div class="footerBox">
            <h3>Terms of Service</h3>
            <hr>
            <p>
            By using our service, you agree to provide accurate booking
            information and comply with our travel and cancellation policies. We
            are not liable for delays or missed trips caused by user error or
            third-party issues.
            </p>
        </div>
        <div class="footerBox">
            <h3>Help & Support</h3>
            <hr>
            <p>
            If you have any questions or need assistance, our support team is
            here to help. Contact us via email or visit our help center for
            answers to frequently asked questions.
            </p>
        </div>
    </div>
    <hr>
    <p class="copy-right">2025 Busket List</p>
</footer>
</body>
</html>