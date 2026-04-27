<?php
session_start();
include 'db_connect.php'; // PDO connection

/* ---------- CLIENT ---------- */
if (isset($_SESSION['id_client'])) {
    $client_id = $_SESSION['id_client'];
} else {
    // Guest info
    $prenom    = $_POST['prenom'] ?? '';
    $nom       = $_POST['nom'] ?? '';
    $email     = $_POST['email'] ?? '';
    $telephone = $_POST['telephone'] ?? null;

    $stmtClient = $conn->prepare("
        INSERT INTO client (prenom, nom, email, telephone, is_guest)
        VALUES (:prenom, :nom, :email, :telephone, 1)
    ");
    $stmtClient->execute([
        'prenom'    => $prenom,
        'nom'       => $nom,
        'email'     => $email,
        'telephone' => $telephone
    ]);

    $client_id = $conn->lastInsertId();
}

/* ---------- DATES ---------- */
// Input from picker: MM/DD/YYYY
$checkin_raw  = $_POST['checkin'] ?? '';
$checkout_raw = $_POST['checkout'] ?? '';

// Parse input safely
$checkin  = DateTime::createFromFormat('m/d/Y h:i A', $_POST['checkin']);
$checkout = DateTime::createFromFormat('m/d/Y h:i A', $_POST['checkout']);


if (!$checkin || !$checkout) die("Format de date invalide.");
if ($checkout <= $checkin) die("La date de départ doit être après la date d'arrivée.");

$date_arrivee = $checkin->format('Y-m-d'); // MySQL format
$date_depart  = $checkout->format('Y-m-d');
$nb_nuits    = max(1, (int) $checkin->diff($checkout)->days);

/* ---------- OTHER BOOKING DATA ---------- */
$nb_adultes        = (int) ($_POST['adultes'] ?? 1);
$nb_enfants        = (int) ($_POST['enfants'] ?? 0);
$id_classe_chambre = (int) ($_POST['classe_chambre'] ?? 1);
$id_type_lit       = (int) ($_POST['type_lit'] ?? 1);
$nb_chambres       = max(1, (int) ($_POST['nb_chambres'] ?? 1));
$message           = $_POST['message'] ?? '';
$id_statut_paiement = 1; // pending

/* ---------- FIND AVAILABLE ROOMS ---------- */
$stmtRooms = $conn->prepare("
    SELECT c.id_chambre
    FROM chambre c
    JOIN classe_chambre_type_lit cctl
        ON c.id_classe_chambre = cctl.id_classe_chambre
    WHERE c.id_classe_chambre = :classe
      AND cctl.id_type_lit = :lit
      AND c.id_chambre NOT IN (
          SELECT rc.id_chambre
          FROM reservation_chambre rc
          JOIN reservation r ON r.id_reservation = rc.id_reservation
          WHERE r.date_depart > :arrivee
            AND r.date_arrivee < :depart
      )
    LIMIT :nb
");

// Bind params
$stmtRooms->bindValue(':classe', $id_classe_chambre, PDO::PARAM_INT);
$stmtRooms->bindValue(':lit', $id_type_lit, PDO::PARAM_INT);
$stmtRooms->bindValue(':arrivee', $date_arrivee);
$stmtRooms->bindValue(':depart', $date_depart);
$stmtRooms->bindValue(':nb', $nb_chambres, PDO::PARAM_INT);

$stmtRooms->execute();
$rooms = $stmtRooms->fetchAll(PDO::FETCH_COLUMN);

if (count($rooms) < $nb_chambres) {
    die("Désolé, il n'y a pas assez de chambres disponibles pour votre sélection.");
}

/* ---------- GET PRICE ---------- */
$stmtPrix = $conn->prepare("
    SELECT prix_base
    FROM classe_chambre
    WHERE id_classe_chambre = :classe
");
$stmtPrix->execute(['classe' => $id_classe_chambre]);
$prix_base = (float) $stmtPrix->fetchColumn();

/* ---------- CALCULATE TOTAL ---------- */
$montant_total = round($prix_base * $nb_nuits * $nb_chambres, 2);

/* ---------- INSERT RESERVATION ---------- */
$stmtRes = $conn->prepare("
    INSERT INTO reservation
    (id_client, id_statut_paiement, date_arrivee, date_depart, nb_adultes, nb_enfants, montant_total)
    VALUES
    (:client, :statut, :arrivee, :depart, :adultes, :enfants, :total)
");
$stmtRes->execute([
    'client'   => $client_id,
    'statut'   => $id_statut_paiement,
    'arrivee'  => $date_arrivee,
    'depart'   => $date_depart,
    'adultes'  => $nb_adultes,
    'enfants'  => $nb_enfants,
    'total'    => $montant_total
]);
$reservation_id = $conn->lastInsertId();

/* ---------- LINK ROOMS ---------- */
$stmtRC = $conn->prepare("
    INSERT INTO reservation_chambre (id_reservation, id_chambre)
    VALUES (:res, :room)
");
foreach ($rooms as $room_id) {
    $stmtRC->execute([
        'res'  => $reservation_id,
        'room' => $room_id
    ]);
}

/* ---------- OPTIONS (optional) ---------- */
if (!empty($_POST['options']) && is_array($_POST['options'])) {
    $stmtOpt = $conn->prepare("
        INSERT INTO reservation_option (id_reservation, id_option, quantite)
        VALUES (:res, :opt, 1)
    ");
    foreach ($_POST['options'] as $opt) {
        $stmtOpt->execute([
            'res' => $reservation_id,
            'opt' => (int) $opt
        ]);
    }
}

/* ---------- SUCCESS REDIRECT ---------- */
header("Location: booking_success.php?reservation_id=$reservation_id");
exit();
?>
