<?php
$movieDir = 'movies';
// Sanitize path and video inputs
$path = isset($_GET['path']) ? trim(filter_var($_GET['path'], FILTER_SANITIZE_STRING, FILTER_FLAG_STRIP_LOW | FILTER_FLAG_STRIP_HIGH), '/') : '';
$video = isset($_GET['video']) ? trim(filter_var($_GET['video'], FILTER_SANITIZE_STRING, FILTER_FLAG_STRIP_LOW | FILTER_FLAG_STRIP_HIGH), '/') : '';

$passwordRequired = false;
$passwordError = false;

// Validate base movie directory
$realMovieDir = realpath($movieDir);
if ($realMovieDir === false) {
    die('Thư mục phim gốc không tồn tại.');
}

// Validate current path against the base directory
$currentFullPath = realpath($movieDir . '/' . $path);
if ($currentFullPath === false || strpos($currentFullPath, $realMovieDir) !== 0) {
    error_log("Invalid path access attempt: MovieDir='{$movieDir}', Path='{$path}', ResolvedFullPath='{$currentFullPath}'");
    die('Đường dẫn không hợp lệ hoặc truy cập bị từ chối.');
}

// Secure the 'secret' folder and its subfolders
if (strpos($path, 'secret') === 0) { // Checks if path starts with 'secret'
    session_start();
    if (!isset($_SESSION['secret_access_granted']) || $_SESSION['secret_access_granted'] !== true) {
        if (isset($_POST['password'])) {
            if ($_POST['password'] === '1234') { // Replace with a strong, configurable password
                $_SESSION['secret_access_granted'] = true;
            } else {
                $passwordRequired = true;
                $passwordError = true;
            }
        } else {
            $passwordRequired = true;
        }
    }
}

// Handle shutdown request
if (isset($_POST['shutdown']) && $_POST['shutdown'] === 'confirm') {
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        // exec('shutdown /s /t 0'); // Disabled for safety
        echo json_encode(['status' => 'success', 'message' => 'Lệnh tắt máy đã được gửi (đã vô hiệu hóa để an toàn).']);
        exit;
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Tính năng tắt máy chỉ hỗ trợ Windows (hiện đã vô hiệu hóa).']);
        exit;
    }
}

$currentDir = $movieDir . ($path ? '/' . $path : '');
$videoFile = null;
$fileExt = ''; // Initialize fileExt
$thumbnail = null;
$description = 'Không có mô tả cho thư mục này.';
$videos = [];
$images = [];
$subDirs = [];

// Handle video download
if (isset($_GET['download']) && $_GET['download'] === 'video' && $video && !$passwordRequired) {
    $videoDownloadPath = $currentDir . '/' . $video;
    // Further validation for download path
    $realVideoDownloadPath = realpath($videoDownloadPath);
    if ($realVideoDownloadPath === false || strpos($realVideoDownloadPath, $realMovieDir) !== 0 || !is_file($realVideoDownloadPath)) {
        error_log("Invalid download attempt: " . $videoDownloadPath);
        die('File video không tồn tại hoặc đường dẫn không hợp lệ.');
    }

    header('Content-Description: File Transfer');
    header('Content-Type: application/octet-stream'); // Generic stream for wider compatibility
    header('Content-Disposition: attachment; filename="' . basename($video) . '"');
    header('Expires: 0');
    header('Cache-Control: must-revalidate');
    header('Pragma: public');
    header('Content-Length: ' . filesize($realVideoDownloadPath));
    flush(); // Flush system output buffer
    readfile($realVideoDownloadPath);
    exit;
}


// Check if current path is a directory and password is provided (if needed)
if (!$passwordRequired && is_dir($currentDir)) {
    $items = scandir($currentDir);
    if ($items === false) {
        die('Không thể đọc thư mục.');
    }
    
    $descriptionPath = $currentDir . '/description.txt';
    if (is_file($descriptionPath) && is_readable($descriptionPath)) {
        $description = file_get_contents($descriptionPath);
    }


    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $fullItemPath = $currentDir . '/' . $item;
        $relItemPath = $path ? $path . '/' . $item : $item;

        if (is_dir($fullItemPath)) {
             if ($item === 'secret' && (strpos($path, 'secret') !== 0 && $path !== 'secret' && $path === '')) { // only hide if 'secret' is a direct subfolder and we are not already in it or its parent
                // This logic might need refinement based on how deep 'secret' can be and how it's accessed.
                // For now, if we are at root and see 'secret', it will be listed, access controlled by main 'secret' check.
            }
            $subDirs[] = ['name' => $item, 'path' => $relItemPath];
        } elseif (is_file($fullItemPath)) {
            $itemExt = strtolower(pathinfo($item, PATHINFO_EXTENSION));
            if (in_array($itemExt, ['mp4', 'mkv', 'avi', 'webm', 'm3u8'])) {
                $videos[$item] = $item; // Store filename as key and value for simplicity
            } elseif (in_array($itemExt, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                $images[$item] = $item;
                if (!$thumbnail && in_array(strtolower($item), ['folder.jpg', 'cover.jpg', 'thumb.jpg', 'poster.jpg'])) {
                    $thumbnail = $item; // Prioritize specific thumbnail names
                }
            }
        }
    }
    if (!$thumbnail && !empty($images)) { // Fallback to first image if no preferred thumb found
        $tempImages = array_values($images); // Get numerically indexed array
        sort($tempImages); // Sort for consistency
        $thumbnail = $tempImages[0];
    }


    ksort($videos);
    ksort($images);
    // Sort subDirs by name, case-insensitive
    usort($subDirs, function($a, $b) {
        return strcasecmp($a['name'], $b['name']);
    });

    // Determine current video and its extension
    if ($video && isset($videos[$video])) {
        $videoFile = $videos[$video];
    } elseif (!empty($videos)) {
        $videoFile = reset($videos); // Default to the first video in the sorted list
    }
    if ($videoFile) {
        $fileExt = strtolower(pathinfo($videoFile, PATHINFO_EXTENSION));
    }

} elseif (!$passwordRequired && !$passwordError) {
    die('Thư mục không tồn tại hoặc không thể truy cập.');
}

$pageTitle = $path ? htmlspecialchars(basename($path)) : 'Trình phát Video';
if ($videoFile) {
    $pageTitle = htmlspecialchars(pathinfo($videoFile, PATHINFO_FILENAME)) . ' - ' . $pageTitle;
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

        #imageModal, #shutdownModal { 
            display: none; opacity: 0; transition: opacity 0.3s ease-in-out;
        }
        #imageModal.show, #shutdownModal.show { display: flex; opacity: 1; }
        .modal-content {
            transform: scale(0.95); opacity: 0;
            transition: transform 0.3s ease-out, opacity 0.3s ease-out;
        }
        #imageModal.show .modal-content, #shutdownModal.show .modal-content {
            transform: scale(1); opacity: 1;
        }
        .search-input:focus { box-shadow: 0 0 0 2px #ef4444; }
        .nav-link { position: relative; transition: color 0.3s ease; }
        .nav-link::after {
            content: ''; position: absolute; width: 0; height: 2px;
            bottom: -4px; left: 50%; background-color: #ef4444;
            transition: width 0.3s ease, left 0.3s ease;
        }
        .nav-link:hover::after, .nav-link.active::after { width: 100%; left: 0; }
        .nav-link:hover, .nav-link.active { color: #f87171; /* red-400 */ }
        .breadcrumb-link { transition: color 0.2s ease; }
        .breadcrumb-link:hover { color: #f87171; /* red-400 */ }
        .btn-primary { background-color: #ef4444; transition: background-color 0.2s ease, transform 0.2s ease; }
        .btn-primary:hover { background-color: #dc2626; transform: translateY(-2px); }
        .btn-secondary { background-color: #4b5563; transition: background-color 0.2s ease, transform 0.2s ease; }
        .btn-secondary:hover { background-color: #374151; transform: translateY(-2px); }
        .modal-close-btn { transition: background-color 0.2s ease, transform 0.2s ease; }
        .modal-close-btn:hover { background-color: #ef4444; transform: scale(1.1); }
        
        #video-list .video-item.active {
            background-color: #ef4444; /* red-500 */
            color: white;
            font-weight: 600;
        }
        #video-list .video-item {
            transition: background-color 0.2s ease, color 0.2s ease, transform 0.2s ease;
        }
        #video-list .video-item:hover {
            background-color: #dc2626; /* red-600 */
            color: white;
            transform: translateX(4px);
        }
        #video-error { display: none; }
        #video-error.show { display: block; }
        .aspect-video { aspect-ratio: 16 / 9; }
    </style>
</head>
<body class="bg-gray-900 text-slate-100 selection:bg-red-500 selection:text-white">
    <!-- Header -->
    <header class="bg-black/80 backdrop-blur-md sticky top-0 z-50 shadow-lg">
        <div class="container mx-auto flex items-center justify-between p-4">
            <a href="index.php" class="text-3xl font-extrabold text-red-500 hover:text-red-400 transition-colors">MovieHub</a>
            <nav class="flex items-center space-x-2 sm:space-x-4">
                <a href="index.php" class="nav-link text-gray-300 px-3 py-2 rounded-md text-sm font-medium flex items-center">
                    <svg class="w-5 h-5 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path></svg>
                    Trang chủ
                </a>
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
        <?php if ($passwordRequired): ?>
            <section class="flex items-center justify-center min-h-[calc(100vh-200px)]">
                <div class="bg-gray-800 p-8 rounded-xl shadow-2xl max-w-md w-full">
                    <h2 class="text-3xl font-bold mb-6 text-center text-red-400">Yêu cầu quyền truy cập</h2>
                    <p class="text-slate-300 text-center mb-6">Thư mục này được bảo vệ bằng mật khẩu.</p>
                    <?php if ($passwordError): ?>
                        <p class="text-red-400 bg-red-900/30 border border-red-700 p-3 rounded-md mb-6 text-sm">Mật khẩu không đúng. Vui lòng thử lại.</p>
                    <?php endif; ?>
                    <form method="POST" action="watch.php?path=<?php echo urlencode($path); ?>&video=<?php echo urlencode($video); ?>">
                        <input type="password" name="password" placeholder="Nhập mật khẩu" class="w-full p-3 mb-6 rounded-lg bg-gray-700 text-slate-100 border border-gray-600 focus:outline-none focus:border-red-500 focus:ring-1 focus:ring-red-500 search-input transition-colors" required autofocus>
                        <button type="submit" class="w-full btn-primary text-white p-3 rounded-lg font-semibold text-lg">Xác nhận</button>
                    </form>
                </div>
            </section>
        <?php else: ?>
            <!-- Breadcrumb -->
            <?php if ($path): ?>
            <nav class="mb-6 text-sm text-slate-400">
                <a href="index.php" class="breadcrumb-link hover:text-red-400">Trang chủ</a>
                <?php
                $pathParts = array_filter(explode('/', $path));
                $builtPath = '';
                foreach ($pathParts as $idx => $part) {
                    $builtPathLink = $builtPath . $part;
                     // For the last part, if it's the current video's parent folder, link to index.php for that folder.
                    // Otherwise, link to watch.php for that folder.
                    $isLastPart = ($idx === count($pathParts) -1);
                    $linkTarget = "index.php?type=movies&path=" . urlencode($builtPathLink);

                    echo ' <span class="mx-1 text-gray-600">></span> <a href="' . $linkTarget . '" class="breadcrumb-link hover:text-red-400">' . htmlspecialchars($part) . '</a>';
                    $builtPath .= $part . '/';
                }
                ?>
            </nav>
            <?php endif; ?>

            <!-- Folders -->
            <?php if (!empty($subDirs)): ?>
                <section class="mb-10">
                    <h2 class="text-2xl font-semibold mb-5 text-red-400">Thư mục con</h2>
                    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-x-6 gap-y-8">
                        <?php foreach ($subDirs as $dir): ?>
                            <a href="index.php?type=movies&path=<?php echo urlencode($dir['path']); ?>"
                               class="item-card block bg-gray-800 p-5 rounded-xl shadow-lg hover:shadow-2xl group">
                                <div class="flex items-center space-x-3">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8 text-red-500 group-hover:text-red-400 transition-colors duration-200" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z" /></svg>
                                    <span class="text-lg font-medium text-slate-200 group-hover:text-white transition-colors duration-200 truncate"><?php echo htmlspecialchars($dir['name']); ?></span>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>

            <!-- Video Player and Video List -->
            <?php if (!empty($videos)): ?>
                <section class="flex flex-col lg:flex-row gap-8 mb-10">
                    <!-- Video Player -->
                    <div class="lg:w-3/4 bg-gray-800 p-1 sm:p-2 rounded-xl shadow-2xl">
                        <div class="relative aspect-video bg-black rounded-lg overflow-hidden">
                            <video id="video-player" class="w-full h-full" controls autoplay playsinline poster="<?php echo $thumbnail ? htmlspecialchars($currentDir . '/' . $thumbnail) : ''; ?>">
                                <?php if ($videoFile && $fileExt !== 'm3u8'): ?>
                                    <source id="video-source" src="<?php echo htmlspecialchars($currentDir . '/' . $videoFile); ?>" type="video/<?php echo $fileExt === 'webm' ? 'webm' : ($fileExt === 'mkv' ? 'mp4' : $fileExt); ?>">
                                <?php endif; ?>
                                Trình duyệt không hỗ trợ video này.
                            </video>
                        </div>
                        <div class="p-4 sm:p-6">
                            <h2 id="video-title" class="text-2xl sm:text-3xl font-bold text-red-400 mb-3 truncate">
                                <?php echo $videoFile ? htmlspecialchars(pathinfo($videoFile, PATHINFO_FILENAME)) : 'Chọn video để phát'; ?>
                            </h2>
                             <p id="video-description" class="text-slate-300 text-sm mb-5 leading-relaxed line-clamp-3">
                                <?php echo nl2br(htmlspecialchars($description)); ?>
                            </p>
                            <?php if ($videoFile): ?>
                                <a id="download-button" href="watch.php?path=<?php echo urlencode($path); ?>&video=<?php echo urlencode($videoFile); ?>&download=video" 
                                   class="btn-primary text-white px-5 py-2.5 rounded-lg font-semibold inline-flex items-center text-sm shadow-md">
                                   <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path></svg>
                                   Tải video
                                </a>
                            <?php endif; ?>
                             <p id="video-error" class="text-red-400 bg-red-900/30 border border-red-700 p-3 rounded-md mt-4 text-sm hidden">Không thể phát video.</p>
                        </div>
                    </div>

                    <!-- Video List -->
                    <aside class="lg:w-1/4 bg-gray-800 p-4 sm:p-6 rounded-xl shadow-2xl">
                        <h3 class="text-xl font-semibold mb-4 text-red-400">Danh sách phát</h3>
                        <ul id="video-list" class="space-y-2 max-h-[60vh] lg:max-h-[calc(100vh-250px)] overflow-y-auto pr-1">
                            <?php foreach ($videos as $vidKey => $vidName): // Use key from $videos which is the filename ?>
                                <li>
                                    <button data-video="<?php echo htmlspecialchars($currentDir . '/' . $vidName); ?>" 
                                            data-ext="<?php echo strtolower(pathinfo($vidName, PATHINFO_EXTENSION)); ?>" 
                                            data-title="<?php echo htmlspecialchars(pathinfo($vidName, PATHINFO_FILENAME)); ?>" 
                                            class="video-item w-full text-left px-4 py-2.5 rounded-md text-sm font-medium bg-gray-700 text-slate-200 hover:bg-red-600 hover:text-white <?php echo $vidName === $videoFile ? 'active' : ''; ?>">
                                        <?php echo htmlspecialchars(pathinfo($vidName, PATHINFO_FILENAME)); ?>
                                    </button>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </aside>
                </section>
            <?php endif; ?>

            <!-- Image List -->
            <?php if (!empty($images)): ?>
                <section class="mb-10">
                    <h2 class="text-2xl font-semibold mb-5 text-red-400">Ảnh trong thư mục</h2>
                    <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-4">
                        <?php 
                        $allImageFullPaths = array_values(array_map(fn($img) => $currentDir . '/' . $img, array_values($images)));
                        foreach ($images as $imageName): ?>
                            <div class="item-card bg-gray-800 rounded-lg shadow-lg overflow-hidden group">
                                <img src="<?php echo htmlspecialchars($currentDir . '/' . $imageName); ?>" 
                                     alt="<?php echo htmlspecialchars($imageName); ?>" 
                                     class="w-full aspect-[4/3] object-cover cursor-pointer group-hover:scale-105 transition-transform duration-300" 
                                     loading="lazy"
                                     onclick='openImageModal("<?php echo htmlspecialchars($currentDir . "/" . $imageName); ?>", <?php echo json_encode($allImageFullPaths); ?>)'>
                                <div class="p-3 hidden sm:block">
                                    <h3 class="text-sm font-medium text-slate-200 group-hover:text-red-400 transition-colors truncate"><?php echo htmlspecialchars(pathinfo($imageName, PATHINFO_FILENAME)); ?></h3>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>

            <?php if (empty($subDirs) && empty($videos) && empty($images) && !$passwordRequired): ?>
                 <div class="text-center py-16">
                    <svg class="mx-auto h-16 w-16 text-gray-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                        <path vector-effect="non-scaling-stroke" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 13h6m-3-3v6m-9 1V7a2 2 0 012-2h6l2 2h6a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2z" />
                    </svg>
                    <h3 class="mt-2 text-xl font-medium text-slate-300">Không có nội dung</h3>
                    <p class="mt-1 text-sm text-gray-500">Thư mục này hiện đang trống hoặc không chứa file media được hỗ trợ.</p>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </main>
    
    <footer class="text-center py-8 mt-12 border-t border-gray-800">
        <p class="text-sm text-gray-500">&copy; <?php echo date('Y'); ?> MovieHub. All rights reserved.</p>
    </footer>

    <script>
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
            if (shutdownModal.classList.contains('show')) {
                 if (event.key === 'Escape') closeShutdownModal();
            }
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
            fetch(window.location.pathname + window.location.search, {
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


        // Video Playback Handling
        const videoPlayer = document.getElementById('video-player');
        const videoSource = document.getElementById('video-source'); // May not exist for HLS
        const videoTitleEl = document.getElementById('video-title');
        const videoErrorEl = document.getElementById('video-error');
        const downloadButton = document.getElementById('download-button');
        let hlsPlayer = null;

        function playVideo(videoSrc, ext, title) {
            if (!videoPlayer) return;

            videoErrorEl.classList.remove('show');
            videoTitleEl.textContent = title;

            if (hlsPlayer) {
                hlsPlayer.destroy();
                hlsPlayer = null;
            }
            // Clear previous non-HLS source if it exists
            if(videoPlayer.querySelector('source')){
                videoPlayer.querySelector('source').src = '';
            }
            videoPlayer.removeAttribute('src'); // Clear src attribute for non-HLS
            videoPlayer.load(); // Reset player state


            if (ext === 'm3u8') {
                if (Hls.isSupported()) {
                    hlsPlayer = new Hls({ debug: false }); // Enable debug for development if needed
                    hlsPlayer.loadSource(videoSrc);
                    hlsPlayer.attachMedia(videoPlayer);
                    hlsPlayer.on(Hls.Events.MANIFEST_PARSED, function() {
                        videoPlayer.play().catch(e => console.error("Error playing HLS: ", e));
                    });
                    hlsPlayer.on(Hls.Events.ERROR, function(event, data) {
                        console.error('HLS Error:', data);
                        videoErrorEl.textContent = `Lỗi HLS: ${data.details} (type: ${data.type})`;
                        videoErrorEl.classList.add('show');
                        if (data.fatal) {
                            switch(data.type) {
                                case Hls.ErrorTypes.NETWORK_ERROR:
                                hlsPlayer.startLoad(); // try to recover network error
                                break;
                                case Hls.ErrorTypes.MEDIA_ERROR:
                                hlsPlayer.recoverMediaError(); // try to recover media error
                                break;
                                default:
                                hlsPlayer.destroy(); // cannot recover
                                break;
                            }
                        }
                    });
                } else if (videoPlayer.canPlayType('application/vnd.apple.mpegurl')) {
                    // Native HLS support (e.g., Safari)
                    videoPlayer.src = videoSrc;
                    videoPlayer.play().catch(e => console.error("Error playing native HLS: ", e));
                } else {
                    videoErrorEl.textContent = 'Trình duyệt không hỗ trợ HLS.';
                    videoErrorEl.classList.add('show');
                }
            } else { // For MP4, WebM, MKV (browser dependent for MKV)
                let mimeType = 'video/mp4'; // Default
                if (ext === 'webm') mimeType = 'video/webm';
                // For MKV, browsers often handle it as MP4 or need specific server-side handling/transcoding.
                // Here, we assume if it's not m3u8 or webm, it's mp4-like.
                
                // Ensure source element exists or create it
                let sourceElement = videoPlayer.querySelector('source#video-source');
                if (!sourceElement) {
                    videoPlayer.innerHTML = ''; // Clear existing children like text node
                    sourceElement = document.createElement('source');
                    sourceElement.id = 'video-source';
                    videoPlayer.appendChild(sourceElement);
                    const fallbackText = document.createTextNode('Trình duyệt không hỗ trợ video này.');
                    videoPlayer.appendChild(fallbackText);
                }
                sourceElement.setAttribute('src', videoSrc);
                sourceElement.setAttribute('type', mimeType);
                
                videoPlayer.load(); // Important to reload the player with the new source
                videoPlayer.play().catch(e => {
                    console.error("Error playing video:", e);
                    videoErrorEl.textContent = 'Không thể phát video. Định dạng có thể không được hỗ trợ hoặc file bị lỗi.';
                    videoErrorEl.classList.add('show');
                });
            }

            // Update download button link
            if (downloadButton) {
                const currentPath = <?php echo json_encode($path); ?>;
                const videoFileName = videoSrc.substring(videoSrc.lastIndexOf('/') + 1);
                downloadButton.href = `watch.php?path=${encodeURIComponent(currentPath)}&video=${encodeURIComponent(videoFileName)}&download=video`;
            }


            // Update active video in list
            document.querySelectorAll('.video-item').forEach(item => {
                item.classList.remove('active');
                if (item.dataset.video === videoSrc) {
                    item.classList.add('active');
                }
            });
        }

        // Add click handlers to video list items
        document.querySelectorAll('.video-item').forEach(item => {
            item.addEventListener('click', (e) => {
                e.preventDefault(); // Prevent any default behavior if it's an anchor
                playVideo(item.dataset.video, item.dataset.ext, item.dataset.title);
            });
        });

        // Initialize first video if available
        <?php if ($videoFile): ?>
        document.addEventListener('DOMContentLoaded', () => {
            // Find the button corresponding to the initial $videoFile and click it
            // or directly call playVideo if the button isn't strictly necessary for init
            const initialVideoSrc = <?php echo json_encode($currentDir . '/' . $videoFile); ?>;
            const initialVideoExt = <?php echo json_encode($fileExt); ?>;
            const initialVideoTitle = <?php echo json_encode(pathinfo($videoFile, PATHINFO_FILENAME)); ?>;
            
            // Check if the element is already marked active by PHP, if so, just ensure player loads
            const activeItem = document.querySelector('.video-item.active');
            if (activeItem && activeItem.dataset.video === initialVideoSrc) {
                 playVideo(initialVideoSrc, initialVideoExt, initialVideoTitle);
            } else {
                // Fallback if PHP didn't mark one (e.g. if $videoFile was auto-selected)
                const firstPlaylistItem = document.querySelector('.video-item');
                if (firstPlaylistItem) {
                     playVideo(firstPlaylistItem.dataset.video, firstPlaylistItem.dataset.ext, firstPlaylistItem.dataset.title);
                }
            }
        });
        <?php endif; ?>

    </script>
</body>
</html>