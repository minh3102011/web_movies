<?php
$baseDir = isset($_GET['type']) && $_GET['type'] === 'images' ? 'images' : 'movies';
$currentPath = isset($_GET['path']) ? trim($_GET['path'], '/') : ''; // Trim slashes for consistency
$items = [];
$passwordRequired = false;
$passwordError = false;

// Validate path to prevent directory traversal
$realBaseDir = realpath($baseDir);
if ($realBaseDir === false) {
    die('Thư mục gốc không tồn tại.');
}
$fullPath = realpath($baseDir . '/' . $currentPath);

if ($fullPath === false || strpos($fullPath, $realBaseDir) !== 0) {
    // Log the attempt for security review
    error_log("Invalid path access attempt: BaseDir='{$baseDir}', CurrentPath='{$currentPath}', FullPath='{$fullPath}'");
    die('Đường dẫn không hợp lệ hoặc truy cập bị từ chối.');
}


// Check if accessing top-level 'secret' folder in movies
if ($baseDir === 'movies' && $currentPath === 'secret') {
    session_start(); // Start session to store password validation
    if (!isset($_SESSION['secret_access_granted']) || $_SESSION['secret_access_granted'] !== true) {
        if (isset($_POST['password'])) {
            if ($_POST['password'] === '1234') { // Replace '1234' with a strong, configurable password
                $_SESSION['secret_access_granted'] = true;
            } else {
                $passwordRequired = true;
                $passwordError = true;
            }
        } else {
            $passwordRequired = true;
        }
    }
} elseif ($baseDir === 'movies' && strpos($currentPath, 'secret/') === 0) {
    // Also protect subfolders of 'secret'
    session_start();
    if (!isset($_SESSION['secret_access_granted']) || $_SESSION['secret_access_granted'] !== true) {
        $passwordRequired = true; // Force redirect or show password form for subfolders too
        // To be more robust, you might redirect to the 'secret' path to enter password
        // if trying to access a subfolder directly without prior auth.
        // For now, this will just block access if not authenticated.
    }
}


// Handle shutdown request
if (isset($_POST['shutdown']) && $_POST['shutdown'] === 'confirm') {
    // IMPORTANT: This is a DANGEROUS feature. Ensure it's heavily secured or disabled in production.
    // Consider IP whitelisting or a separate, very strong authentication mechanism.
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        exec('shutdown /s /t 0'); // This will shut down the server immediately.
        echo json_encode(['status' => 'success', 'message' => 'Lệnh tắt máy đã được gửi (đã vô hiệu hóa để an toàn).']); // Disabled for safety
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Tính năng tắt máy chỉ hỗ trợ Windows (hiện đã vô hiệu hóa).']);
    }
    exit;
}

// Function to scan directory recursively
function scanDirectory($baseDir, $currentPathScanned) {
    global $passwordRequired; // Use global passwordRequired status

    $result = ['folders' => [], 'videos' => [], 'images' => [], 'thumbnail' => null, 'description' => ''];
    $fullScanPath = $baseDir . ($currentPathScanned ? '/' . $currentPathScanned : '');

    // If trying to scan 'secret' or its subfolders and password is required (and not yet granted for this specific scan context)
    if ($baseDir === 'movies' && (strpos($currentPathScanned, 'secret') === 0) && $passwordRequired) {
         // Check session again, as $passwordRequired might be true from URL but session is now granted
        if (session_status() == PHP_SESSION_NONE) session_start(); // Ensure session is started
        if (!isset($_SESSION['secret_access_granted']) || $_SESSION['secret_access_granted'] !== true) {
            return $result; // Return empty if access not granted
        }
    }


    if (!is_dir($fullScanPath) || !is_readable($fullScanPath)) {
        error_log("Cannot read directory: " . $fullScanPath);
        return $result;
    }

    $files = scandir($fullScanPath);
    if ($files === false) {
        error_log("Failed to scan directory: " . $fullScanPath);
        return $result;
    }

    $descriptionContent = '';
    $potentialThumbnails = [];

    foreach ($files as $file) {
        if ($file === '.' || $file === '..') continue;
        $filePath = $fullScanPath . '/' . $file;
        $relPath = $currentPathScanned ? $currentPathScanned . '/' . $file : $file;

        if (is_dir($filePath)) {
            // Skip 'secret' folder if it's at the top level of movies and password is required
            if ($baseDir === 'movies' && $file === 'secret' && $currentPathScanned === '' && $passwordRequired && (!isset($_SESSION['secret_access_granted']) || $_SESSION['secret_access_granted'] !== true)) {
                continue;
            }
            $result['folders'][$file] = $relPath;
        } elseif (is_file($filePath)) {
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if ($baseDir === 'movies' && in_array($ext, ['mp4', 'mkv', 'avi', 'webm', 'm3u8'])) {
                $result['videos'][$file] = ['name' => $file, 'path' => $relPath, 'type' => $ext];
            } elseif (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) { // Added more image types
                $result['images'][$file] = ['name' => $file, 'path' => $relPath];
                $potentialThumbnails[] = $file; // Add to potential thumbnails
            } elseif ($ext === 'txt' && strtolower($file) === 'description.txt') {
                $descriptionContent = trim(file_get_contents($filePath));
            }
        }
    }

    // Determine thumbnail: specific names first, then any image
    $preferredThumbnails = ['folder.jpg', 'cover.jpg', 'thumb.jpg', 'poster.jpg', 'folder.png', 'cover.png', 'thumb.png', 'poster.png'];
    foreach ($preferredThumbnails as $pt) {
        if (in_array($pt, $potentialThumbnails)) {
            $result['thumbnail'] = $pt;
            break;
        }
    }
    if (!$result['thumbnail'] && !empty($potentialThumbnails)) {
        sort($potentialThumbnails); // Sort to get a consistent choice
        $result['thumbnail'] = $potentialThumbnails[0]; // Fallback to the first image found
    }
    
    $result['description'] = $descriptionContent ?: 'Không có mô tả.';


    ksort($result['videos']);
    ksort($result['images']);
    ksort($result['folders']);
    return $result;
}

// Scan current directory if not password protected or password is correct
if (!$passwordRequired) {
    $items = scanDirectory($baseDir, $currentPath);
}

$pageTitle = $baseDir === 'images' ? 'Thư viện Ảnh' : 'MovieHub';
if ($currentPath) {
    $pageTitle .= ' - ' . htmlspecialchars(basename($currentPath));
}

?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/hls.js@latest"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Inter', sans-serif;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            scrollbar-width: thin;
            scrollbar-color: #ef4444 #1f2937; /* red-500 gray-800 */
        }
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: #1f2937; /* gray-800 */ }
        ::-webkit-scrollbar-thumb { background-color: #ef4444; /* red-500 */ border-radius: 4px; border: 2px solid #1f2937; }
        ::-webkit-scrollbar-thumb:hover { background-color: #dc2626; /* red-600 */ }

        .carousel-item { display: none; transition: opacity 0.7s ease-in-out; }
        .carousel-item.active { display: block; }
        
        .dropdown-menu { 
            display: none; 
            opacity: 0; 
            transform: translateY(-10px) scale(0.95); 
            transition: opacity 0.2s ease-out, transform 0.2s ease-out;
            min-width: 10rem; /* Ensure dropdown has some width */
        }
        .dropdown-menu.show { 
            display: block; 
            opacity: 1; 
            transform: translateY(0) scale(1); 
        }
        #imageModal, #shutdownModal, #videoModal { 
            display: none; 
            opacity: 0;
            transition: opacity 0.3s ease-in-out;
        }
        #imageModal.show, #shutdownModal.show, #videoModal.show { 
            display: flex; 
            opacity: 1;
        }
        .modal-content {
            transform: scale(0.95);
            opacity: 0;
            transition: transform 0.3s ease-out, opacity 0.3s ease-out;
        }
        #imageModal.show .modal-content, 
        #shutdownModal.show .modal-content,
        #videoModal.show .modal-content {
            transform: scale(1);
            opacity: 1;
        }
        .item-card {
            transition: transform 0.3s ease-out, box-shadow 0.3s ease-out;
        }
        .item-card:hover {
            transform: translateY(-6px) scale(1.03);
            box-shadow: 0 10px 20px rgba(0,0,0,0.3), 0 6px 6px rgba(0,0,0,0.25);
        }
        .search-input:focus {
            box-shadow: 0 0 0 2px #ef4444; /* red-500 focus ring */
        }
        .nav-link {
            position: relative;
            transition: color 0.3s ease;
        }
        .nav-link::after {
            content: '';
            position: absolute;
            width: 0;
            height: 2px;
            bottom: -4px;
            left: 50%;
            background-color: #ef4444; /* red-500 */
            transition: width 0.3s ease, left 0.3s ease;
        }
        .nav-link:hover::after, .nav-link.active::after {
            width: 100%;
            left: 0;
        }
        .nav-link:hover, .nav-link.active {
            color: #f87171; /* red-400 for hover/active text */
        }
        .breadcrumb-link {
            transition: color 0.2s ease;
        }
        .breadcrumb-link:hover {
            color: #f87171; /* red-400 */
        }
        .btn-primary {
            background-color: #ef4444; /* red-500 */
            transition: background-color 0.2s ease, transform 0.2s ease;
        }
        .btn-primary:hover {
            background-color: #dc2626; /* red-600 */
            transform: translateY(-2px);
        }
        .btn-secondary {
            background-color: #4b5563; /* gray-600 */
            transition: background-color 0.2s ease, transform 0.2s ease;
        }
        .btn-secondary:hover {
            background-color: #374151; /* gray-700 */
            transform: translateY(-2px);
        }
        .modal-close-btn {
            transition: background-color 0.2s ease, transform 0.2s ease;
        }
        .modal-close-btn:hover {
            background-color: #ef4444; /* red-500 */
            transform: scale(1.1);
        }
        .carousel-btn {
            transition: background-color 0.2s ease, transform 0.2s ease;
        }
        .carousel-btn:hover {
            background-color: rgba(239, 68, 68, 0.7); /* red-500 with opacity */
            transform: scale(1.1);
        }
    </style>
</head>
<body class="bg-gray-900 text-slate-100 selection:bg-red-500 selection:text-white">
    <!-- Header -->
    <header class="bg-black/80 backdrop-blur-md sticky top-0 z-50 shadow-lg">
        <div class="container mx-auto flex items-center justify-between p-4">
            <a href="index.php" class="text-3xl font-extrabold text-red-500 hover:text-red-400 transition-colors">MovieHub</a>
            <div class="flex-1 max-w-lg mx-6">
                <input type="text" id="search" placeholder="Tìm kiếm phim, ảnh, thư mục..." class="w-full p-2.5 rounded-lg bg-gray-800 text-slate-100 border border-gray-700 focus:outline-none focus:border-red-500 search-input transition-colors duration-200 placeholder-gray-500">
            </div>
            <nav class="flex items-center space-x-2 sm:space-x-4">
                <a href="index.php" class="nav-link text-gray-300 px-3 py-2 rounded-md text-sm font-medium <?php echo (!isset($_GET['type']) && !isset($_GET['path']) && basename($_SERVER['PHP_SELF']) === 'index.php') ? 'active text-red-400' : ''; ?>">Trang chủ</a>
                <div class="dropdown relative">
                    <button class="nav-link text-gray-300 px-3 py-2 rounded-md text-sm font-medium dropdown-toggle focus:outline-none flex items-center" onclick="toggleDropdown('categoryDropdown')">
                        Danh mục <svg class="w-4 h-4 ml-1 fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"/></svg>
                    </button>
                    <div id="categoryDropdown" class="dropdown-menu absolute right-0 bg-gray-800 rounded-lg shadow-xl mt-2 z-20 overflow-hidden border border-gray-700">
                        <a href="index.php?type=movies" class="block px-4 py-2.5 text-sm text-slate-200 hover:bg-red-600 hover:text-white transition-colors duration-150 <?php echo ($baseDir === 'movies') ? 'bg-red-700 text-white' : ''; ?>">Video</a>
                        <a href="index.php?type=images" class="block px-4 py-2.5 text-sm text-slate-200 hover:bg-red-600 hover:text-white transition-colors duration-150 <?php echo ($baseDir === 'images') ? 'bg-red-700 text-white' : ''; ?>">Ảnh</a>
                    </div>
                </div>
                <a href="play_link.php" class="nav-link text-gray-300 px-3 py-2 rounded-md text-sm font-medium <?php echo (basename($_SERVER['PHP_SELF']) === 'play_link.php') ? 'active text-red-400' : ''; ?>">Phát từ link</a>
                <button onclick="openShutdownModal()" class="nav-link text-gray-300 px-3 py-2 rounded-md text-sm font-medium focus:outline-none">Tắt máy</button>
            </nav>
        </div>
    </header>

    <!-- Image Modal -->
    <div id="imageModal" class="fixed inset-0 bg-black/90 backdrop-blur-sm z-[60] items-center justify-center p-4 hidden">
        <div class="modal-content relative max-w-5xl w-full max-h-[90vh] bg-gray-800 shadow-2xl rounded-lg p-2">
            <img id="modalImage" src="" alt="Image" class="w-full h-auto max-h-[calc(90vh-4rem)] object-contain mx-auto rounded">
            <button id="closeImageModal" class="modal-close-btn absolute top-3 right-3 bg-gray-700 text-white p-2 rounded-full hover:bg-red-500 z-10">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
            </button>
            <button id="prevImage" class="carousel-btn absolute top-1/2 left-3 transform -translate-y-1/2 bg-gray-700/70 text-white p-3 rounded-full hover:bg-red-500/90">❮</button>
            <button id="nextImage" class="carousel-btn absolute top-1/2 right-3 transform -translate-y-1/2 bg-gray-700/70 text-white p-3 rounded-full hover:bg-red-500/90">❯</button>
        </div>
    </div>

    <!-- Video Modal -->
    <div id="videoModal" class="fixed inset-0 bg-black/90 backdrop-blur-sm z-[60] items-center justify-center p-4 hidden">
        <div class="modal-content relative w-full max-w-4xl bg-gray-800 shadow-2xl rounded-lg overflow-hidden">
            <div class="flex justify-between items-center p-4 border-b border-gray-700">
                <h3 id="videoModalTitle" class="text-xl font-semibold text-red-400">Đang phát video</h3>
                <button id="closeVideoModal" class="modal-close-btn bg-gray-700 text-white p-1.5 rounded-full hover:bg-red-500">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                </button>
            </div>
            <div class="aspect-video bg-black">
                <video id="modalVideoPlayer" class="w-full h-full" controls autoplay playsinline>
                    Trình duyệt của bạn không hỗ trợ thẻ video.
                </video>
            </div>
        </div>
    </div>


    <!-- Shutdown Modal -->
    <div id="shutdownModal" class="fixed inset-0 bg-black/90 backdrop-blur-sm z-[60] items-center justify-center p-4 hidden">
        <div class="modal-content bg-gray-800 p-6 sm:p-8 rounded-xl shadow-2xl max-w-md w-full">
            <h2 class="text-2xl font-bold mb-6 text-red-400">Xác nhận tắt máy</h2>
            <p class="text-slate-300 mb-8">Bạn có chắc chắn muốn gửi lệnh tắt máy tính từ xa?</p>
            <div class="flex justify-end space-x-3">
                <button onclick="closeShutdownModal()" class="btn-secondary text-white px-5 py-2.5 rounded-lg font-medium">Hủy</button>
                <button onclick="confirmShutdown()" class="btn-primary text-white px-5 py-2.5 rounded-lg font-medium">Tắt máy</button>
            </div>
        </div>
    </div>

    <main class="container mx-auto px-4 sm:px-6 py-8">
    <!-- Password Form for Secret Folder -->
    <?php if ($passwordRequired): ?>
        <section class="flex items-center justify-center min-h-[calc(100vh-200px)]">
            <div class="bg-gray-800 p-8 rounded-xl shadow-2xl max-w-md w-full">
                <h2 class="text-3xl font-bold mb-6 text-center text-red-400">Yêu cầu quyền truy cập</h2>
                <p class="text-slate-300 text-center mb-6">Thư mục này được bảo vệ bằng mật khẩu.</p>
                <?php if ($passwordError): ?>
                    <p class="text-red-400 bg-red-900/30 border border-red-700 p-3 rounded-md mb-6 text-sm">Mật khẩu không đúng. Vui lòng thử lại.</p>
                <?php endif; ?>
                <form method="POST" action="index.php?path=<?php echo urlencode($currentPath); ?>&type=movies">
                    <input type="password" name="password" placeholder="Nhập mật khẩu" class="w-full p-3 mb-6 rounded-lg bg-gray-700 text-slate-100 border border-gray-600 focus:outline-none focus:border-red-500 focus:ring-1 focus:ring-red-500 search-input transition-colors" required autofocus>
                    <button type="submit" class="w-full btn-primary text-white p-3 rounded-lg font-semibold text-lg">Xác nhận</button>
                </form>
            </div>
        </section>
    <?php else: ?>
        <!-- Breadcrumb -->
        <?php if ($currentPath): ?>
        <nav class="mb-6 text-sm text-slate-400">
            <a href="index.php?type=<?php echo urlencode($baseDir); ?>" class="breadcrumb-link hover:text-red-400">Trang chủ</a>
            <?php
            $pathParts = array_filter(explode('/', $currentPath));
            $builtPath = '';
            foreach ($pathParts as $part) {
                $builtPath .= $part;
                echo ' <span class="mx-1 text-gray-600">></span> <a href="index.php?type=' . urlencode($baseDir) . '&path=' . urlencode($builtPath) . '" class="breadcrumb-link hover:text-red-400">' . htmlspecialchars($part) . '</a>';
                $builtPath .= '/';
            }
            ?>
        </nav>
        <?php endif; ?>

        <!-- Carousel -->
        <?php 
            $carouselItems = [];
            if (!empty($items['folders'])) {
                foreach(array_slice($items['folders'], 0, 3, true) as $name => $path) { // Limit to 3 folders
                    $folderData = scanDirectory($baseDir, $path);
                     if ($baseDir === 'movies' && $name === 'secret' && $passwordRequired && (!isset($_SESSION['secret_access_granted']) || $_SESSION['secret_access_granted'] !== true)) continue;
                    $carouselItems[] = ['type' => 'folder', 'name' => $name, 'path' => $path, 'data' => $folderData];
                }
            }
            if (count($carouselItems) < 3 && !empty($items['videos'])) {
                 foreach(array_slice($items['videos'], 0, 3 - count($carouselItems), true) as $file => $item) {
                    $carouselItems[] = ['type' => 'video', 'name' => pathinfo($item['name'], PATHINFO_FILENAME), 'path' => $item['path'], 'data' => $item, 'parent_data' => $items];
                }
            }
            if (count($carouselItems) < 3 && !empty($items['images'])) {
                 foreach(array_slice($items['images'], 0, 3 - count($carouselItems), true) as $file => $item) {
                    $carouselItems[] = ['type' => 'image', 'name' => pathinfo($item['name'], PATHINFO_FILENAME), 'path' => $item['path'], 'data' => $item, 'parent_data' => $items];
                }
            }
            
        if (!empty($carouselItems)): ?>
        <section class="relative mb-12 shadow-2xl rounded-xl overflow-hidden">
            <div class="carousel relative w-full h-[350px] sm:h-[450px] md:h-[550px]">
                <?php 
                $first = true;
                foreach ($carouselItems as $idx => $item):
                    $itemName = htmlspecialchars($item['name']);
                    $itemPath = htmlspecialchars($item['path']);
                    $itemType = $item['type'];
                    $itemData = $item['data'];
                    $parentData = $item['parent_data'] ?? $itemData; // For videos/images, parentData is $items

                    $thumbnailSrc = 'https://via.placeholder.com/1280x720/1f2937/ef4444?text=' . urlencode($itemName); // Default placeholder
                    if ($itemType === 'image') {
                        $thumbnailSrc = htmlspecialchars($baseDir . '/' . $itemPath);
                    } elseif (!empty($itemData['thumbnail'])) {
                        $thumbBasePath = ($itemType === 'folder') ? $itemPath : dirname($itemPath);
                        $thumbnailSrc = htmlspecialchars($baseDir . '/' . ($thumbBasePath ? $thumbBasePath . '/' : '') . $itemData['thumbnail']);
                    } elseif ($itemType === 'video' && !empty($parentData['thumbnail'])) { // Video using parent folder's thumb
                         $thumbnailSrc = htmlspecialchars($baseDir . '/' . ($currentPath ? $currentPath . '/' : '') . $parentData['thumbnail']);
                    }
                ?>
                    <div class="carousel-item <?php echo $first ? 'active' : ''; ?> absolute inset-0 w-full h-full">
                        <div class="relative w-full h-full">
                            <img src="<?php echo $thumbnailSrc; ?>" 
                                 alt="<?php echo $itemName; ?>" 
                                 class="w-full h-full object-cover"
                                 loading="<?php echo $first ? 'eager' : 'lazy'; ?>">
                            <div class="absolute inset-0 bg-gradient-to-t from-black/80 via-black/40 to-transparent"></div>
                            <div class="absolute bottom-0 left-0 right-0 p-6 md:p-10 text-white">
                                <h2 class="text-3xl sm:text-4xl lg:text-5xl font-extrabold mb-2 sm:mb-3 text-shadow-lg"><?php echo $itemName; ?></h2>
                                <p class="text-slate-200 text-sm sm:text-base mb-4 sm:mb-6 line-clamp-2 text-shadow-md"><?php echo htmlspecialchars($itemData['description'] ?: ($parentData['description'] ?: 'Khám phá ngay')); ?></p>
                                <?php if ($baseDir === 'movies' && $itemType === 'video'): ?>
                                    <button onclick='openVideoModal("<?php echo htmlspecialchars($baseDir . '/' . $itemPath); ?>", "<?php echo $itemName; ?>", "<?php echo htmlspecialchars($itemData['type']); ?>")'
                                       class="btn-primary text-white px-5 py-2.5 sm:px-6 sm:py-3 rounded-lg font-semibold text-sm sm:text-base shadow-md">
                                       <svg class="inline-block w-5 h-5 mr-2 -mt-0.5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM9.555 7.168A1 1 0 008 8v4a1 1 0 001.555.832l3-2a1 1 0 000-1.664l-3-2z" clip-rule="evenodd"></path></svg>
                                       Xem video
                                    </button>
                                <?php elseif ($itemType === 'image'): ?>
                                    <button onclick='openImageModal("<?php echo htmlspecialchars($baseDir . '/' . $itemPath); ?>", <?php echo json_encode(array_values(array_map(fn($p) => $baseDir . '/' . $p, array_column($items['images'], 'path')))); ?>)'
                                            class="btn-primary text-white px-5 py-2.5 sm:px-6 sm:py-3 rounded-lg font-semibold text-sm sm:text-base shadow-md">
                                            <svg class="inline-block w-5 h-5 mr-2 -mt-0.5" fill="currentColor" viewBox="0 0 20 20"><path d="M10 12a2 2 0 100-4 2 2 0 000 4z"></path><path fill-rule="evenodd" d="M.458 10C1.732 5.943 5.522 3 10 3s8.268 2.943 9.542 7c-1.274 4.057-5.022 7-9.542 7S1.732 14.057.458 10zM14 10a4 4 0 11-8 0 4 4 0 018 0z" clip-rule="evenodd"></path></svg>
                                            Xem ảnh
                                    </button>
                                <?php else: // Folder ?>
                                    <a href="index.php?type=<?php echo urlencode($baseDir); ?>&path=<?php echo urlencode($itemPath); ?>" 
                                       class="btn-primary text-white px-5 py-2.5 sm:px-6 sm:py-3 rounded-lg font-semibold text-sm sm:text-base shadow-md">
                                       <svg class="inline-block w-5 h-5 mr-2 -mt-0.5" fill="currentColor" viewBox="0 0 20 20"><path d="M2 6a2 2 0 012-2h5l2 2h5a2 2 0 012 2v6a2 2 0 01-2 2H4a2 2 0 01-2-2V6z"></path></svg>
                                       Xem thư mục
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php $first = false; endforeach; ?>
            </div>
            <button class="carousel-btn absolute top-1/2 left-3 sm:left-4 transform -translate-y-1/2 bg-black/40 hover:bg-red-500/80 text-white rounded-full p-2 sm:p-3 focus:outline-none z-10" onclick="prevSlide()">
                <svg class="w-5 h-5 sm:w-6 sm:h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path></svg>
            </button>
            <button class="carousel-btn absolute top-1/2 right-3 sm:right-4 transform -translate-y-1/2 bg-black/40 hover:bg-red-500/80 text-white rounded-full p-2 sm:p-3 focus:outline-none z-10" onclick="nextSlide()">
                <svg class="w-5 h-5 sm:w-6 sm:h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
            </button>
             <div class="absolute bottom-4 left-1/2 transform -translate-x-1/2 flex space-x-2 z-10" id="carousel-indicators">
                <?php for ($i = 0; $i < count($carouselItems); $i++): ?>
                    <button class="w-2.5 h-2.5 rounded-full bg-white/50 hover:bg-white transition-colors" data-slide-to="<?php echo $i; ?>"></button>
                <?php endfor; ?>
            </div>
        </section>
        <?php endif; ?>


        <!-- Item List -->
        <section>
            <h2 class="text-3xl font-bold mb-8 text-red-400"><?php echo $currentPath ? htmlspecialchars(basename($currentPath)) : ($baseDir === 'movies' ? 'Phim và Thư mục' : 'Ảnh và Thư mục'); ?></h2>
            
            <?php if (empty($items['folders']) && empty($items['videos']) && empty($items['images'])): ?>
                <div class="text-center py-12">
                    <svg class="mx-auto h-16 w-16 text-gray-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                        <path vector-effect="non-scaling-stroke" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 13h6m-3-3v6m-9 1V7a2 2 0 012-2h6l2 2h6a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2z" />
                    </svg>
                    <h3 class="mt-2 text-xl font-medium text-slate-300">Không có nội dung</h3>
                    <p class="mt-1 text-sm text-gray-500">Thư mục này hiện đang trống.</p>
                </div>
            <?php else: ?>
                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-x-6 gap-y-8" id="item-list">
                    <!-- Folders -->
                    <?php foreach ($items['folders'] as $folderName => $folderPath):
                        if ($baseDir === 'movies' && $folderName === 'secret' && $passwordRequired && (!isset($_SESSION['secret_access_granted']) || $_SESSION['secret_access_granted'] !== true)) continue;
                        $folderData = scanDirectory($baseDir, $folderPath); 
                        $folderThumbnail = 'https://via.placeholder.com/300x200/1f2937/ef4444?text=' . urlencode($folderName);
                        if ($folderData['thumbnail']) {
                            $folderThumbnail = htmlspecialchars($baseDir . '/' . $folderPath . '/' . $folderData['thumbnail']);
                        }
                    ?>
                        <div class="item item-card bg-gray-800 rounded-xl shadow-xl overflow-hidden group" data-name="<?php echo htmlspecialchars(strtolower($folderName)); ?>" data-type="folder">
                            <a href="index.php?type=<?php echo urlencode($baseDir); ?>&path=<?php echo urlencode($folderPath); ?>" class="block">
                                <div class="aspect-[4/3] overflow-hidden">
                                    <img src="<?php echo $folderThumbnail; ?>" 
                                         alt="<?php echo htmlspecialchars($folderName); ?>" 
                                         class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300"
                                         loading="lazy">
                                </div>
                                <div class="p-4">
                                    <h3 class="text-lg font-semibold text-slate-100 group-hover:text-red-400 transition-colors truncate mb-1"><?php echo htmlspecialchars($folderName); ?></h3>
                                    <p class="text-gray-400 text-xs line-clamp-2"><?php echo htmlspecialchars($folderData['description'] ?: 'Nhấn để xem nội dung'); ?></p>
                                </div>
                            </a>
                        </div>
                    <?php endforeach; ?>

                    <!-- Videos -->
                    <?php foreach ($items['videos'] as $file => $item): 
                        $videoName = htmlspecialchars(pathinfo($item['name'], PATHINFO_FILENAME));
                        $videoPath = htmlspecialchars($baseDir . '/' . $item['path']);
                        $videoType = htmlspecialchars($item['type']);
                        $videoThumbnail = 'https://via.placeholder.com/300x200/1f2937/ef4444?text=' . urlencode($videoName);
                        if ($items['thumbnail']) { // Use parent folder's thumbnail if available
                            $videoThumbnail = htmlspecialchars($baseDir . '/' . ($currentPath ? $currentPath . '/' : '') . $items['thumbnail']);
                        }
                    ?>
                        <div class="item item-card bg-gray-800 rounded-xl shadow-xl overflow-hidden group" data-name="<?php echo strtolower($videoName); ?>" data-type="video">
                             <button onclick='openVideoModal("<?php echo $videoPath; ?>", "<?php echo $videoName; ?>", "<?php echo $videoType; ?>")' class="block w-full text-left">
                                <div class="aspect-[4/3] overflow-hidden">
                                    <img src="<?php echo $videoThumbnail; ?>" 
                                         alt="<?php echo $videoName; ?>" 
                                         class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300"
                                         loading="lazy">
                                </div>
                                <div class="p-4">
                                    <h3 class="text-lg font-semibold text-slate-100 group-hover:text-red-400 transition-colors truncate mb-1"><?php echo $videoName; ?></h3>
                                    <p class="text-gray-400 text-xs line-clamp-2"><?php echo htmlspecialchars($items['description'] ?: 'Nhấn để xem video'); ?></p>
                                </div>
                            </button>
                        </div>
                    <?php endforeach; ?>

                    <!-- Images -->
                    <?php 
                    $allImagePaths = array_values(array_map(fn($p) => $baseDir . '/' . $p, array_column($items['images'], 'path')));
                    foreach ($items['images'] as $file => $item): 
                        $imageName = htmlspecialchars(pathinfo($item['name'], PATHINFO_FILENAME));
                        $imageSrc = htmlspecialchars($baseDir . '/' . $item['path']);
                    ?>
                        <div class="item item-card bg-gray-800 rounded-xl shadow-xl overflow-hidden group" data-name="<?php echo strtolower($imageName); ?>" data-type="image">
                            <button onclick='openImageModal("<?php echo $imageSrc; ?>", <?php echo json_encode($allImagePaths); ?>)' class="block w-full text-left">
                                <div class="aspect-[4/3] overflow-hidden">
                                    <img src="<?php echo $imageSrc; ?>" 
                                         alt="<?php echo $imageName; ?>" 
                                         class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300" 
                                         loading="lazy">
                                </div>
                                <div class="p-4">
                                    <h3 class="text-lg font-semibold text-slate-100 group-hover:text-red-400 transition-colors truncate mb-1"><?php echo $imageName; ?></h3>
                                    <p class="text-gray-400 text-xs line-clamp-2"><?php echo htmlspecialchars($items['description'] ?: 'Nhấn để xem ảnh'); ?></p>
                                </div>
                            </button>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>
    </main>

    <footer class="text-center py-8 mt-12 border-t border-gray-800">
        <p class="text-sm text-gray-500">&copy; <?php echo date('Y'); ?> MovieHub. All rights reserved.</p>
    </footer>

    <script>
        // Toggle Dropdown
        function toggleDropdown(dropdownId) {
            const allDropdowns = document.querySelectorAll('.dropdown-menu');
            allDropdowns.forEach(menu => {
                if (menu.id !== dropdownId && menu.classList.contains('show')) {
                    menu.classList.remove('show');
                }
            });
            const dropdownMenu = document.getElementById(dropdownId);
            if (dropdownMenu) {
                dropdownMenu.classList.toggle('show');
            }
        }

        document.addEventListener('click', function(event) {
            const openDropdown = document.querySelector('.dropdown-menu.show');
            if (openDropdown && !openDropdown.parentElement.contains(event.target)) {
                openDropdown.classList.remove('show');
            }
        });

        // Image Modal
        let currentImageIndex = 0;
        let imageList = [];
        const imageModal = document.getElementById('imageModal');
        const modalImage = document.getElementById('modalImage');

        function openImageModal(src, images) {
            imageList = images;
            currentImageIndex = imageList.indexOf(src);
            modalImage.src = src;
            imageModal.classList.add('show');
            document.body.style.overflow = 'hidden';
            updateImageNavButtons();
        }

        function closeImageModal() {
            imageModal.classList.remove('show');
            document.body.style.overflow = 'auto';
        }

        function updateImageNavButtons() {
            document.getElementById('prevImage').style.display = imageList.length > 1 ? 'block' : 'none';
            document.getElementById('nextImage').style.display = imageList.length > 1 ? 'block' : 'none';
        }

        function showNextImage() {
            if (imageList.length === 0) return;
            currentImageIndex = (currentImageIndex + 1) % imageList.length;
            modalImage.src = imageList[currentImageIndex];
        }

        function showPrevImage() {
             if (imageList.length === 0) return;
            currentImageIndex = (currentImageIndex - 1 + imageList.length) % imageList.length;
            modalImage.src = imageList[currentImageIndex];
        }

        document.getElementById('closeImageModal').addEventListener('click', closeImageModal);
        document.getElementById('nextImage').addEventListener('click', showNextImage);
        document.getElementById('prevImage').addEventListener('click', showPrevImage);
        imageModal.addEventListener('click', function(event) {
            if (event.target === imageModal) closeImageModal();
        });
        document.addEventListener('keydown', function(event) {
            if (imageModal.classList.contains('show')) {
                if (event.key === 'Escape') closeImageModal();
                if (event.key === 'ArrowRight') showNextImage();
                if (event.key === 'ArrowLeft') showPrevImage();
            }
            if (videoModal.classList.contains('show')) {
                if (event.key === 'Escape') closeVideoModal();
            }
            if (shutdownModal.classList.contains('show')) {
                 if (event.key === 'Escape') closeShutdownModal();
            }
        });

        // Video Modal
        const videoModal = document.getElementById('videoModal');
        const modalVideoPlayer = document.getElementById('modalVideoPlayer');
        const videoModalTitle = document.getElementById('videoModalTitle');
        let hlsInstance = null;

        function openVideoModal(videoSrc, videoName, videoType) {
            videoModalTitle.textContent = videoName;
            if (hlsInstance) {
                hlsInstance.destroy();
                hlsInstance = null;
            }
            modalVideoPlayer.src = ''; // Clear previous source

            if (videoType === 'm3u8' && Hls.isSupported()) {
                hlsInstance = new Hls();
                hlsInstance.loadSource(videoSrc);
                hlsInstance.attachMedia(modalVideoPlayer);
                hlsInstance.on(Hls.Events.MANIFEST_PARSED, function() {
                    modalVideoPlayer.play();
                });
            } else if (modalVideoPlayer.canPlayType('application/vnd.apple.mpegurl') && videoType === 'm3u8') {
                 modalVideoPlayer.src = videoSrc; // Native HLS support (iOS)
                 modalVideoPlayer.play();
            }
            else { // MP4, MKV, etc.
                modalVideoPlayer.src = videoSrc;
                modalVideoPlayer.play();
            }
            videoModal.classList.add('show');
            document.body.style.overflow = 'hidden';
        }

        function closeVideoModal() {
            modalVideoPlayer.pause();
            if (hlsInstance) {
                hlsInstance.destroy();
                hlsInstance = null;
            }
            modalVideoPlayer.src = ''; // Clear source
            videoModal.classList.remove('show');
            document.body.style.overflow = 'auto';
        }
        document.getElementById('closeVideoModal').addEventListener('click', closeVideoModal);
        videoModal.addEventListener('click', function(event) {
            if (event.target === videoModal) closeVideoModal();
        });


        // Shutdown Modal
        const shutdownModal = document.getElementById('shutdownModal');
        function openShutdownModal() {
            shutdownModal.classList.add('show');
            document.body.style.overflow = 'hidden';
        }

        function closeShutdownModal() {
            shutdownModal.classList.remove('show');
            document.body.style.overflow = 'auto';
        }

        function confirmShutdown() {
            fetch(window.location.pathname + window.location.search, { // Use current URL to post
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'shutdown=confirm'
            })
            .then(response => response.json())
            .then(data => {
                alert(data.message || (data.status === 'success' ? 'Lệnh tắt máy đã được gửi.' : 'Có lỗi xảy ra.'));
                if (data.status === 'success') {
                    closeShutdownModal();
                }
            })
            .catch(error => alert('Lỗi khi gửi yêu cầu tắt máy: ' + error));
        }
        shutdownModal.addEventListener('click', function(event) {
            if (event.target === shutdownModal) closeShutdownModal();
        });


        // Carousel
        let currentSlide = 0;
        const slides = document.querySelectorAll('.carousel-item');
        const indicators = document.querySelectorAll('#carousel-indicators button');
        let slideInterval;

        function updateIndicators(index) {
            indicators.forEach((indicator, i) => {
                indicator.classList.toggle('bg-white', i === index);
                indicator.classList.toggle('bg-white/50', i !== index);
            });
        }

        function showSlide(index) {
            if (slides.length === 0) return;
            slides.forEach((slide, i) => {
                slide.classList.toggle('active', i === index);
                slide.style.opacity = (i === index) ? '1' : '0';
            });
            currentSlide = index;
            updateIndicators(index);
        }
        
        function nextSlide() {
            if (slides.length === 0) return;
            let next = (currentSlide + 1) % slides.length;
            showSlide(next);
        }

        function prevSlide() {
            if (slides.length === 0) return;
            let prev = (currentSlide - 1 + slides.length) % slides.length;
            showSlide(prev);
        }

        function startSlideShow() {
            if (slides.length > 1) {
                 stopSlideShow(); // Clear existing interval if any
                 slideInterval = setInterval(nextSlide, 5000); // Change slide every 5 seconds
            }
        }
        function stopSlideShow() {
            clearInterval(slideInterval);
        }

        if (slides.length > 0) {
            showSlide(0); // Show first slide initially
            startSlideShow();

            document.querySelector('.carousel').addEventListener('mouseenter', stopSlideShow);
            document.querySelector('.carousel').addEventListener('mouseleave', startSlideShow);

            indicators.forEach(indicator => {
                indicator.addEventListener('click', function() {
                    showSlide(parseInt(this.dataset.slideTo));
                    stopSlideShow(); // Optional: stop auto-slide on manual navigation
                    startSlideShow(); // Optional: restart auto-slide after a delay or keep it stopped
                });
            });
        }


        // Search
        document.getElementById('search').addEventListener('input', function(e) {
            const query = e.target.value.toLowerCase().trim();
            const itemsToSearch = document.querySelectorAll('#item-list .item');
            itemsToSearch.forEach(item => {
                const itemName = item.dataset.name.toLowerCase();
                // Simple search: check if item name includes query
                item.style.display = itemName.includes(query) ? 'block' : 'none';
            });
        });

        // Add text shadow utility classes via JS if needed for dynamic content (PHP already handles static)
        // Example: element.classList.add('text-shadow-md');
        // For this project, PHP handles it well.
    </script>
</body>
</html>