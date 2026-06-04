<?php
declare(strict_types=1);

require __DIR__ . '/api/bootstrap.php';
mx_security_headers();
header('Cache-Control: private, no-store, max-age=0');

$requestId = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$token = mx_clean_string($_GET['token'] ?? $_POST['token'] ?? '', 128);
$request = mx_courier_task_request($requestId, $token);
$error = '';
$notice = mx_clean_string($_GET['notice'] ?? '', 40);

if (!$request) {
    http_response_code(404);
    echo 'Görev bağlantısı geçersiz veya artık kullanılamıyor.';
    exit;
}

$saveProof = static function (array $file, string $proofType, array $request, string $deliveredTo, string $note): string {
    if (!mx_table_exists('courier_delivery_proofs')) {
        throw new RuntimeException('Kurye kanıt tablosu hazır değil.');
    }
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Fotoğraf yüklenemedi.');
    }
    if ((int) ($file['size'] ?? 0) <= 0 || (int) $file['size'] > 8 * 1024 * 1024) {
        throw new RuntimeException('Fotoğraf boyutu 8 MB altında olmalıdır.');
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string) $finfo->file($tmpName);
    $extensions = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    if (!isset($extensions[$mime]) || @getimagesize($tmpName) === false) {
        throw new RuntimeException('Yalnızca JPG, PNG veya WebP fotoğraf yüklenebilir.');
    }

    $directory = mx_courier_proof_directory();
    if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
        throw new RuntimeException('Fotoğraf klasörü hazırlanamadı.');
    }

    $fileName = sprintf(
        '%s-%s-%s.%s',
        (int) $request['id'],
        $proofType,
        bin2hex(random_bytes(12)),
        $extensions[$mime]
    );
    if (!move_uploaded_file($tmpName, $directory . DIRECTORY_SEPARATOR . $fileName)) {
        throw new RuntimeException('Fotoğraf kaydedilemedi.');
    }

    mx_pdo()->prepare(
        'INSERT INTO courier_delivery_proofs
            (request_id, courier_id, proof_type, file_name, mime_type, delivered_to, note, ip_address, user_agent)
         VALUES
            (:request_id, :courier_id, :proof_type, :file_name, :mime_type, :delivered_to, :note, :ip_address, :user_agent)'
    )->execute([
        ':request_id' => (int) $request['id'],
        ':courier_id' => (int) $request['assigned_courier_id'],
        ':proof_type' => $proofType,
        ':file_name' => $fileName,
        ':mime_type' => $mime,
        ':delivered_to' => $deliveredTo !== '' ? $deliveredTo : null,
        ':note' => $note !== '' ? $note : null,
        ':ip_address' => mx_client_ip(),
        ':user_agent' => mx_clean_string($_SERVER['HTTP_USER_AGENT'] ?? '', 255),
    ]);

    return $fileName;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = mx_clean_string($_POST['action'] ?? '', 32);
    $note = mx_clean_text($_POST['note'] ?? '', 500);
    $deliveredTo = mx_clean_string($_POST['delivered_to'] ?? '', 160);
    $savedFileName = null;

    try {
        if (in_array((string) $request['status'], ['cancelled', 'delivered'], true)) {
            throw new RuntimeException('Bu görev için yeni işlem yapılamaz.');
        }

        $pdo = mx_pdo();
        $pdo->beginTransaction();
        if ($action === 'pickup') {
            $savedFileName = $saveProof($_FILES['proof_photo'] ?? [], 'pickup', $request, '', $note);
            $newStatus = 'picked_up';
            $statusNote = 'Kurye teslim alma fotoğrafını yükledi.' . ($note !== '' ? ' Not: ' . $note : '');
            $auditAction = 'courier_pickup_proof';
            $mailTitle = 'Gönderiniz teslim alındı.';
            $mailBody = 'Kuryemiz gönderiyi teslim alma noktasından teslim aldı.';
        } elseif ($action === 'delivery') {
            if ((string) $request['status'] !== 'picked_up') {
                throw new RuntimeException('Teslim kaydı için önce gönderi teslim alınmalıdır.');
            }
            if ($deliveredTo === '') {
                throw new RuntimeException('Teslim edilen kişi bilgisi zorunludur.');
            }
            $savedFileName = $saveProof($_FILES['proof_photo'] ?? [], 'delivery', $request, $deliveredTo, $note);
            $newStatus = 'delivered';
            $statusNote = 'Kurye teslim fotoğrafını yükledi. Teslim edilen kişi: ' . $deliveredTo . ($note !== '' ? ' Not: ' . $note : '');
            $auditAction = 'courier_delivery_proof';
            $mailTitle = 'Gönderiniz teslim edildi.';
            $mailBody = 'Gönderiniz ' . $deliveredTo . ' kişisine teslim edildi.';
        } else {
            throw new RuntimeException('Geçersiz işlem.');
        }

        $statusUpdate = $pdo->prepare('UPDATE courier_requests SET status = :status WHERE id = :id AND assigned_courier_id = :courier_id');
        $statusUpdate->execute([
            ':status' => $newStatus,
            ':id' => (int) $request['id'],
            ':courier_id' => (int) $request['assigned_courier_id'],
        ]);
        if ($statusUpdate->rowCount() !== 1) {
            throw new RuntimeException('Talep durumu güncellenemedi. Görevi yeniden açın.');
        }
        $pdo->prepare('INSERT INTO request_status_logs (request_id, status, note) VALUES (:id, :status, :note)')
            ->execute([
                ':id' => (int) $request['id'],
                ':status' => $newStatus,
                ':note' => $statusNote,
            ]);
        $pdo->commit();

        mx_audit_log(
            (int) $request['id'],
            $auditAction,
            $statusNote,
            'kurye:' . (string) $request['courier_name']
        );
        $updatedRequest = mx_request_by_id((int) $request['id']);
        if ($updatedRequest) {
            mx_send_request_customer_mail(
                $updatedRequest,
                'MyExpress gönderi durumu güncellendi: ' . $updatedRequest['tracking_code'],
                $mailTitle,
                $mailBody
            );
        }

        header('Location: kurye-gorev.php?id=' . (int) $request['id'] . '&token=' . rawurlencode($token) . '&notice=' . $newStatus);
        exit;
    } catch (Throwable $exception) {
        if (mx_pdo()->inTransaction()) {
            mx_pdo()->rollBack();
        }
        if ($savedFileName !== null) {
            $savedPath = mx_courier_proof_absolute_path($savedFileName);
            if ($savedPath !== null) {
                @unlink($savedPath);
            }
        }
        $error = $exception->getMessage();
        mx_log_error('courier task action failed', $exception, [
            'request_id' => $requestId,
            'action' => $action,
        ]);
    }
}

$request = mx_courier_task_request($requestId, $token) ?: $request;
$proofs = [];
if (mx_table_exists('courier_delivery_proofs')) {
    $proofStmt = mx_pdo()->prepare(
        'SELECT id, proof_type, delivered_to, note, created_at
         FROM courier_delivery_proofs
         WHERE request_id = :request_id AND courier_id = :courier_id
         ORDER BY created_at DESC'
    );
    $proofStmt->execute([
        ':request_id' => (int) $request['id'],
        ':courier_id' => (int) $request['assigned_courier_id'],
    ]);
    $proofs = $proofStmt->fetchAll();
}

$pickupAddress = trim(implode(', ', array_filter([(string) $request['pickup'], (string) $request['pickup_street']])));
$dropoffAddress = trim(implode(', ', array_filter([(string) $request['dropoff'], (string) $request['dropoff_street']])));
$canPickup = !in_array((string) $request['status'], ['picked_up', 'delivered', 'cancelled'], true);
$canDeliver = (string) $request['status'] === 'picked_up';
?>
<!doctype html>
<html lang="tr">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= mx_h($request['tracking_code']) ?> | MyExpress Kurye Görevi</title>
    <link rel="icon" type="image/png" href="assets/Logo.png">
    <link rel="stylesheet" href="styles.css?v=20260604-courier-task">
  </head>
  <body class="courier-task-body">
    <main class="courier-task-shell">
      <header class="courier-task-header">
        <img src="assets/Logo.png" alt="MyExpress">
        <div>
          <p class="eyebrow">Kurye görevi</p>
          <h1><?= mx_h($request['tracking_code']) ?></h1>
          <span class="panel-status panel-status-<?= mx_h($request['status']) ?>"><?= mx_h(mx_status_label((string) $request['status'])) ?></span>
        </div>
      </header>

      <?php if ($notice !== ''): ?>
        <div class="courier-task-notice">İşlem kaydedildi. Talep durumu güncellendi.</div>
      <?php endif; ?>
      <?php if ($error !== ''): ?>
        <div class="courier-task-error"><?= mx_h($error) ?></div>
      <?php endif; ?>

      <section class="courier-task-grid">
        <article class="courier-task-card">
          <p class="courier-task-label">Alım</p>
          <h2><?= mx_h($request['sender_name']) ?></h2>
          <a href="tel:<?= mx_h($request['sender_phone']) ?>"><?= mx_h($request['sender_phone']) ?></a>
          <p><?= mx_h($pickupAddress) ?></p>
          <a class="courier-task-map-link" href="https://www.google.com/maps/search/?api=1&query=<?= rawurlencode($pickupAddress . ', İstanbul') ?>" target="_blank" rel="noopener">Haritada Aç</a>
        </article>
        <article class="courier-task-card">
          <p class="courier-task-label">Teslim</p>
          <h2><?= mx_h($request['recipient_name']) ?></h2>
          <a href="tel:<?= mx_h($request['recipient_phone']) ?>"><?= mx_h($request['recipient_phone']) ?></a>
          <p><?= mx_h($dropoffAddress) ?></p>
          <a class="courier-task-map-link" href="https://www.google.com/maps/search/?api=1&query=<?= rawurlencode($dropoffAddress . ', İstanbul') ?>" target="_blank" rel="noopener">Haritada Aç</a>
        </article>
      </section>

      <section class="courier-task-card courier-task-shipment">
        <div>
          <p class="courier-task-label">Gönderi</p>
          <h2><?= mx_h($request['service_label'] ?: $request['service']) ?> · <?= mx_h($request['package_label'] ?: $request['package_type']) ?></h2>
        </div>
        <div>
          <p class="courier-task-label">Teslim zamanı</p>
          <p><?= mx_h($request['delivery_time'] ?: 'En kısa sürede') ?></p>
        </div>
        <?php if (!empty($request['note'])): ?>
          <div>
            <p class="courier-task-label">Operasyon notu</p>
            <p><?= mx_h($request['note']) ?></p>
          </div>
        <?php endif; ?>
      </section>

      <section class="courier-task-actions">
        <form class="courier-task-card courier-task-form" method="post" enctype="multipart/form-data">
          <input type="hidden" name="id" value="<?= (int) $request['id'] ?>">
          <input type="hidden" name="token" value="<?= mx_h($token) ?>">
          <input type="hidden" name="action" value="pickup">
          <h2>Gönderiyi Teslim Al</h2>
          <p>Gönderiyi teslim aldığınız anda paketin net göründüğü bir fotoğraf yükleyin.</p>
          <label>Fotoğraf
            <input type="file" name="proof_photo" accept="image/jpeg,image/png,image/webp" capture="environment" required <?= $canPickup ? '' : 'disabled' ?>>
          </label>
          <label>Not <textarea name="note" maxlength="500" placeholder="Paket durumu veya teslim alma notu" <?= $canPickup ? '' : 'disabled' ?>></textarea></label>
          <button class="btn btn-primary" type="submit" <?= $canPickup ? '' : 'disabled' ?>>Teslim Aldım</button>
        </form>

        <form class="courier-task-card courier-task-form" method="post" enctype="multipart/form-data">
          <input type="hidden" name="id" value="<?= (int) $request['id'] ?>">
          <input type="hidden" name="token" value="<?= mx_h($token) ?>">
          <input type="hidden" name="action" value="delivery">
          <h2>Gönderiyi Teslim Et</h2>
          <p>Teslim noktasında paketin ve teslim ortamının anlaşılır olduğu bir fotoğraf yükleyin.</p>
          <label>Teslim edilen kişi
            <input type="text" name="delivered_to" maxlength="160" placeholder="Ad soyad veya teslim alan görevli" required <?= $canDeliver ? '' : 'disabled' ?>>
          </label>
          <label>Fotoğraf
            <input type="file" name="proof_photo" accept="image/jpeg,image/png,image/webp" capture="environment" required <?= $canDeliver ? '' : 'disabled' ?>>
          </label>
          <label>Not <textarea name="note" maxlength="500" placeholder="Teslim noktası veya teslim notu" <?= $canDeliver ? '' : 'disabled' ?>></textarea></label>
          <button class="btn btn-primary" type="submit" <?= $canDeliver ? '' : 'disabled' ?>>Teslim Ettim</button>
        </form>
      </section>

      <?php if ($proofs): ?>
        <section class="courier-task-card courier-task-proof-list">
          <h2>Kaydedilen İşlemler</h2>
          <?php foreach ($proofs as $proof): ?>
            <article>
              <a href="kurye-kanit.php?id=<?= (int) $proof['id'] ?>&amp;request=<?= (int) $request['id'] ?>&amp;token=<?= mx_h(rawurlencode($token)) ?>" target="_blank" rel="noopener">
                <img src="kurye-kanit.php?id=<?= (int) $proof['id'] ?>&amp;request=<?= (int) $request['id'] ?>&amp;token=<?= mx_h(rawurlencode($token)) ?>" alt="<?= $proof['proof_type'] === 'pickup' ? 'Teslim alma fotoğrafı' : 'Teslim fotoğrafı' ?>">
              </a>
              <div>
                <strong><?= $proof['proof_type'] === 'pickup' ? 'Teslim alma' : 'Teslim' ?></strong>
                <span><?= mx_h($proof['created_at']) ?></span>
                <?php if (!empty($proof['delivered_to'])): ?><span>Teslim edilen kişi: <?= mx_h($proof['delivered_to']) ?></span><?php endif; ?>
                <?php if (!empty($proof['note'])): ?><span><?= mx_h($proof['note']) ?></span><?php endif; ?>
              </div>
            </article>
          <?php endforeach; ?>
        </section>
      <?php endif; ?>
    </main>
  </body>
</html>
