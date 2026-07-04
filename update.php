<?php
include 'db.php';
require_once __DIR__ . '/auth.php';

ini_set('display_errors', 1);
error_reporting(E_ALL);

auth_require_roles(['admin', 'secretariat']);

$id = $_SESSION['id'];

$query = "SELECT * FROM users WHERE id = '$id'";
$result = mysqli_query($conn, $query);
if (!$result) {
    die("Error fetching user data: " . mysqli_error($conn));
}
$user = mysqli_fetch_assoc($result);

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = mysqli_real_escape_string($conn, $_POST['username']);
    $name = mysqli_real_escape_string($conn, $_POST['name']);
    $profile_picture = $user['profile_picture'];

    if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] == 0) {
        $file_name = $_FILES['profile_picture']['name'];
        $file_tmp = $_FILES['profile_picture']['tmp_name'];
        $file_type = $_FILES['profile_picture']['type'];

        $allowed_types = ['image/jpeg', 'image/png', 'image/jpg'];
        $max_size = 5 * 1024 * 1024;

        if (in_array($file_type, $allowed_types) && $_FILES['profile_picture']['size'] <= $max_size) {
            $new_file_name = uniqid() . '_' . basename($file_name);
            $target_dir = "uploads/profile_pictures/";
            if (!is_dir($target_dir)) {
                mkdir($target_dir, 0755, true);
            }
            $target_file = $target_dir . $new_file_name;

            if (move_uploaded_file($file_tmp, $target_file)) {
                $profile_picture = $new_file_name;
            } else {
                $error_message = "Error moving uploaded file: " . error_get_last()['message'];
            }
        } else {
            $error_message = "Invalid file type or file too large.";
        }
    } else if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] !== UPLOAD_ERR_OK){
        switch ($_FILES['profile_picture']['error']) {
                case UPLOAD_ERR_INI_SIZE:
                    $error_message = "The uploaded file exceeds the upload_max_filesize directive in php.ini.";
                    break;
                case UPLOAD_ERR_FORM_SIZE:
                    $error_message = "The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form.";
                    break;
                case UPLOAD_ERR_PARTIAL:
                    $error_message = "The uploaded file was only partially uploaded.";
                    break;
                case UPLOAD_ERR_NO_FILE:
                    $error_message = "No file was uploaded.";
                    break;
                case UPLOAD_ERR_NO_TMP_DIR:
                    $error_message = "Missing a temporary folder.";
                    break;
                case UPLOAD_ERR_CANT_WRITE:
                    $error_message = "Failed to write file to disk.";
                    break;
                case UPLOAD_ERR_EXTENSION:
                    $error_message = "A PHP extension stopped the file upload.";
                    break;
                default:
                    $error_message = "Unknown upload error.";
            }
    }

    $update_query = "UPDATE users SET username = ?, name = ?, profile_picture = ? WHERE id = ?";
    if ($stmt = mysqli_prepare($conn, $update_query)) {
        mysqli_stmt_bind_param($stmt, 'sssi', $username, $name, $profile_picture, $id);

        if (mysqli_stmt_execute($stmt)) {
            $_SESSION['username'] = $username;
            auth_redirect_to_dashboard();
        } else {
            $error_message = "Error updating profile: " . mysqli_stmt_error($stmt);
        }

        mysqli_stmt_close($stmt);
    } else {
        $error_message = "Failed to prepare the update query: " . mysqli_error($conn);
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Update Profile</title>
    <link href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-5">
        <h2>Update Profile</h2>

        <!-- Display error message if any -->
        <?php if (isset($error_message)): ?>
            <div class="alert alert-danger"><?= $error_message ?></div>
        <?php endif; ?>

        <form action="update.php" method="POST" enctype="multipart/form-data">
            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" class="form-control" id="username" name="username" value="<?= htmlspecialchars($user['username']) ?>" required>
            </div>

            <div class="form-group">
                <label for="name">Name</label>
                <input type="text" class="form-control" id="name" name="name" value="<?= htmlspecialchars($user['name']) ?>" required>
            </div>

            <div class="form-group">
                <label for="profile_picture">Profile Picture</label>
                <input type="file" class="form-control-file" id="profile_picture" name="profile_picture">
                <?php if (!empty($user['profile_picture'])): ?>
                    <img src="uploads/profile_pictures/<?= $user['profile_picture'] ?>" alt="Profile Picture" class="mt-3" style="width: 150px; height: 150px; object-fit: cover; border-radius: 50%;">
                <?php endif; ?>
            </div>

            <button type="submit" class="btn btn-primary">Update Profile</button>
        </form>
    </div>

    <!-- Bootstrap 4 JS -->
    <script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.5.3/dist/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
</body>
</html>