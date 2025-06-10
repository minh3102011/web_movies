<?php
header('Content-Type: text/html; charset=utf-8');
header('Access-Control-Allow-Origin: *');
$movieDir = 'movies_link'; // Changed from 'movies' to 'movies_link'
$ignoreFolders = ['harrypotter 2', 'secret'];
$movieList = [];
$errorMessage = '';
$successMessage = '';

function getMoviesRecursive($baseDir, $ignoreFolders)
{
    $movieData = [];
    // Chuẩn hóa đường dẫn cho Windows (thay \ thành /)
    $baseDir = str_replace('\\', '/', realpath($baseDir));
    if (!is_dir($baseDir) || !is_readable($baseDir)) {
        error_log("Cannot read directory: $baseDir");
        return $movieData;
    }

    // Tìm tất cả thư mục con trong $baseDir
    $dirs = array_filter(glob($baseDir . '/*'), 'is_dir');
    if ($dirs === false) {
        error_log("glob failed for: $baseDir/*");
        return $movieData;
    }

    foreach ($dirs as $dirPath) {
        $dirPath = str_replace('\\', '/', realpath($dirPath));
        $dirName = basename($dirPath);
        if (in_array($dirName, $ignoreFolders)) continue;

        // Tìm file .txt trong thư mục hiện tại
        $txtFiles = glob($dirPath . '/*.txt');
        if ($txtFiles === false) {
            error_log("glob failed for txt files in: $dirPath/*.txt");
            continue;
        }

        $movies = [];
        foreach ($txtFiles as $file) {
            $file = str_replace('\\', '/', realpath($file));
            if (!is_readable($file)) {
                error_log("Cannot read file: $file");
                continue;
            }

            $movieName = basename($file, '.txt');
            $content = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $link = isset($content[0]) ? trim($content[0]) : '';
            $thumbURL = isset($content[1]) ? trim($content[1]) : '';
            if ($link) {
                $movies[] = [
                    'name' => $movieName,
                    'link' => $link,
                    'thumb' => $thumbURL,
                    'filePath' => $file
                ];
            }
        }

        // Đệ quy để lấy subfolder
        $subFolders = getMoviesRecursive($dirPath, $ignoreFolders);
        $movieData[$dirName] = [
            'movies' => $movies,
            'sub' => $subFolders
        ];
        error_log("Processed folder: $dirName, movies: " . count($movies) . ", subfolders: " . count($subFolders));
    }
    return $movieData;
}

$movieList = getMoviesRecursive($movieDir, $ignoreFolders);

// Xử lý xoá phim
if (isset($_GET['delete'])) {
    $deletePath = $_GET['delete'];
    $base = basename($deletePath, '.txt');

    if (file_exists($deletePath)) {
        unlink($deletePath);
        $successMessage = "🗑 Đã xoá phim \"$base\".";
    } else {
        $errorMessage = "❌ Không tìm thấy file cần xoá.";
    }
    // Refresh list after action
    $movieList = getMoviesRecursive($movieDir, $ignoreFolders);
}

// Xử lý thêm thumbnail
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_thumbnail'])) {
    $filePath = $_POST['file_path'];
    $thumbURL = trim($_POST['thumb_url'] ?? '');

    if ($thumbURL && filter_var($thumbURL, FILTER_VALIDATE_URL)) {
        $base = basename($filePath, '.txt');
        // Read existing content (movie link)
        $fileContents = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $movieLink = $fileContents[0] ?? ''; // Assuming link is always the first line

        if ($movieLink) {
            file_put_contents($filePath, $movieLink . "\n" . $thumbURL);
            $successMessage = "✅ Đã thêm thumbnail URL cho \"$base\".";
        } else {
            $errorMessage = "❌ Không thể đọc link phim từ file để thêm thumbnail.";
        }
    } else {
        $errorMessage = "❌ URL thumbnail không hợp lệ.";
    }
    // Refresh list after action
    $movieList = getMoviesRecursive($movieDir, $ignoreFolders);
}

// Xử lý thêm phim
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['add_thumbnail'])) {
    $selectedFolder = trim($_POST['selected_folder'] ?? '');
    $newFolder = trim($_POST['new_folder'] ?? '');
    $movieName = trim($_POST['moviename'] ?? '');
    $movieLink = trim($_POST['movielink'] ?? '');
    $thumbURL = trim($_POST['thumburl'] ?? '');

    if (!$movieName || !$movieLink) {
        $errorMessage = '❌ Vui lòng nhập tên phim và link phim.';
    } else {
        $targetFolder = '';
        if ($selectedFolder && $newFolder) { // Subfolder within selected
            $targetFolder = "$selectedFolder/$newFolder";
        } elseif ($selectedFolder) { // Root of selected
            $targetFolder = $selectedFolder;
        } elseif ($newFolder) { // New root folder
            $targetFolder = $newFolder;
        } else {
            $errorMessage = '❌ Vui lòng chọn hoặc nhập thư mục lưu phim.';
        }

        if (empty($errorMessage)) {
            $folderPath = "$movieDir/$targetFolder";
            if (!is_dir($folderPath)) {
                if (!mkdir($folderPath, 0777, true)) {
                    $errorMessage = "❌ Không thể tạo thư mục: $folderPath";
                }
            }

            if (empty($errorMessage)) {
                $safeMovie = preg_replace('/[^A-Za-z0-9\-\_\.\s]/', '', $movieName); // Sanitize movie name
                $filePath = "$folderPath/$safeMovie.txt";
                $fileContent = $movieLink;
                if ($thumbURL && filter_var($thumbURL, FILTER_VALIDATE_URL)) {
                    $fileContent .= "\n" . $thumbURL;
                }

                if (file_put_contents($filePath, $fileContent) === false) {
                    $errorMessage = "❌ Không thể ghi file phim: $filePath";
                } else {
                    $successMessage = "✅ Đã thêm phim \"$safeMovie\" vào thư mục \"$targetFolder\".";
                }
            }
        }
    }
    // Refresh list after action
    $movieList = getMoviesRecursive($movieDir, $ignoreFolders);
}

// Lấy danh sách thư mục hiện có
$existingFolders = array_values(array_filter(scandir($movieDir), function ($dir) use ($movieDir, $ignoreFolders) {
    return is_dir("$movieDir/$dir") && !in_array($dir, ['.', '..']) && !in_array($dir, $ignoreFolders);
}));

// Xử lý xem phim
$playLink = $_GET['play'] ?? '';
$validLink = false;
$activeLink = '';
$linkType = '';

if ($playLink) {
    $ext = strtolower(pathinfo(parse_url($playLink, PHP_URL_PATH), PATHINFO_EXTENSION));
    $validExtensions = ['mp4', 'mkv', 'avi', 'webm', 'm3u8'];
    if (in_array($ext, $validExtensions)) {
        $validLink = true;
        $activeLink = $playLink;
        $linkType = $ext === 'm3u8' ? 'hls' : 'video';
    } else {
        $errorMessage = '❌ Link không hợp lệ (chỉ hỗ trợ: mp4, mkv, avi, webm, m3u8)';
    }
}

// Xử lý hiển thị thư mục cụ thể
$currentFolder = trim($_GET['folder'] ?? '', '/');
$currentFolderData = ['movies' => [], 'sub' => []];

if ($currentFolder) {
    $pathParts = array_filter(explode('/', $currentFolder));
    $tempData = $movieList; // Start with the full list
    $resolvedData = null;

    foreach ($pathParts as $part) {
        if (isset($tempData[$part])) {
            $resolvedData = $tempData[$part]; // Move into this part
            $tempData = $resolvedData['sub'] ?? []; // Next level of subfolders
        } else {
            $resolvedData = null; // Path part not found
            error_log("Folder part not found: $part in path " . implode('/', $pathParts));
            break;
        }
    }

    if ($resolvedData) {
        $currentFolderData = [
            'movies' => $resolvedData['movies'] ?? [],
            'sub' => $resolvedData['sub'] ?? []
        ];
    } else {
        // If path is invalid, show empty or error
        $currentFolderData = ['movies' => [], 'sub' => []];
        if (!$playLink) { // Don't overwrite play link error
            $errorMessage = $errorMessage ?: "❌ Không tìm thấy thư mục: " . htmlspecialchars($currentFolder);
        }
    }
} else {
    // Root view: show top-level folders as 'sub' and no 'movies' at root
    $currentFolderData = ['movies' => [], 'sub' => $movieList];
}

?>

<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0"> <!-- Added for mobile responsiveness -->
    <title>Quản lý phim thư mục</title>
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
        }

        .text-shadow-md {
            text-shadow: 0 2px 8px rgba(0, 0, 0, 0.3);
        }

        .text-shadow-sm {
            text-shadow: 0 1px 4px rgba(0, 0, 0, 0.2);
        }

        /* Custom Scrollbar */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }

        ::-webkit-scrollbar-track {
            background: #0f172a;
        }

        ::-webkit-scrollbar-thumb {
            background: #38bdf8;
            border-radius: 4px;
            border: 1px solid #0f172a;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: #0ea5e9;
        }

        .video-js .vjs-control-bar {
            background-color: rgba(43, 51, 63, 0.7) !important;
        }

        /* Dropdown adjustments for mobile */
        .dropdown-menu {
            display: none;
        }

        .dropdown:hover .dropdown-menu,
        .dropdown.active .dropdown-menu {
            display: block;
        }

        /* Responsive adjustments */

        h1 {
            font-size: 2rem;
            /* Smaller heading on mobile */
        }

        h2,
        h3,
        h4 {
            font-size: 1.5rem;
        }

        .grid {
            grid-template-columns: 1fr;
            /* Single column on mobile */
        }

        button,
        a.button {
            padding: 0.75rem 1.5rem;
            /* Larger touch targets */
            font-size: 0.875rem;
        }

        input,
        select {
            padding: 0.75rem;
            /* Larger input fields */
            font-size: 0.875rem;
        }

        .dropdown-menu {
            width: 100%;
            /* Full width on mobile */
            right: 0;
        }




        .movie-grid {
            display: grid;
            grid-template-columns: repeat(1, 1fr);
            /* 1 column on mobile */
            gap: 1rem;
            /* Spacing between items */
            padding-bottom: 1rem;
        }

        .video-preview.selected::after {
            content: '▶️';
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            font-size: 1.5rem;
            color: rgba(255, 255, 255, 0.7);
            display: block;
        }

        /* Video preview styling */
        .video-preview.playing::after {
            content: '▶️';
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            font-size: 2rem;
            color: rgba(255, 255, 255, 0.7);
            display: none;
        }

        @media (max-width: 640px) {
            .movie-grid {
                gap: 0.75rem;
                /* Smaller gap on mobile */
            }

            .movie-grid>div {
                min-height: 180px;
                /* Slightly smaller on mobile */
            }

            .video-preview {
                aspect-ratio: 3/2;
                /* Better proportion for mobile */
            }
        }

        .movie-list {
            display: flex;
            flex-direction: column;
            gap: 1rem;
            /* Spacing between list items */
            max-height: 80vh;
            /* Limit height to show multiple items in viewport */
            overflow-y: auto;
            /* Enable scrolling for long lists */
            padding-bottom: 1rem;
        }

        .movie-list>div {
            display: flex;
            flex-direction: row;
            width: 100%;
            min-height: 100px;
            /* Ensure items are tall enough for visibility */
        }

        .movie-list>div>div:first-child {
            width: 33.333%;
            /* Thumbnail takes 1/3 width */
            max-width: 150px;
            /* Limit thumbnail width for consistency */
        }

        .movie-list>div>div:last-child {
            width: 66.667%;
            /* Content takes 2/3 width */
            display: flex;
            flex-direction: column;
        }

        @media (min-width: 640px) {
            .movie-grid {
                grid-template-columns: repeat(2, 1fr);
                /* 2 columns on small screens */
            }
        }

        @media (min-width: 768px) {
            .movie-grid {
                grid-template-columns: repeat(3, 1fr);
                /* 3 columns on medium screens */
            }
        }

        @media (min-width: 1024px) {
            .movie-grid {
                grid-template-columns: repeat(4, 1fr);
                /* 4 columns on large screens */
            }
        }

        @media (min-width: 1280px) {
            .movie-grid {
                grid-template-columns: repeat(5, 1fr);
                /* 5 columns on extra-large screens */
            }
        }

        .movie-grid>div {
            width: 100%;
            min-height: 200px;
            /* Ensure items are compact for multiple rows */
        }

        @media (max-width: 639px) {
            .movie-list>div {
                flex-direction: column;
            }

            .movie-list>div>div:first-child {
                width: 100%;
                aspect-ratio: 3/2;
            }
        }
    </style>
</head>

<body class="min-h-screen bg-slate-900 text-slate-200 font-sans antialiased">
    <div class="container mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-6 md:py-10">
        <!-- Navigation Dropdown -->
        <div class="relative flex justify-end mb-6">
            <div class="dropdown">
                <button id="dropdownToggle" class="bg-gray-800 text-slate-200 px-4 py-2 rounded-lg hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-sky-500 transition-colors duration-150 flex items-center space-x-2">
                    <span>Danh mục</span>
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
                    </svg>
                </button>
                <div id="categoryDropdown" class="dropdown-menu absolute right-0 bg-gray-800 rounded-lg shadow-xl mt-2 z-20 overflow-hidden border border-gray-700 w-40">
                    <a href="index.php?type=movies" class="block px-4 py-2.5 text-sm text-slate-200 hover:bg-red-600 hover:text-white transition-colors duration-150 <?php echo (basename($_SERVER['PHP_SELF']) === 'play_link.php' ? 'bg-red-500 text-white' : '') ?>">Video</a>
                    <a href="index.php?type=images" class="block px-4 py-2.5 text-sm text-slate-200 hover:bg-red-600 hover:text-white transition-colors duration-150">Image</a>
                </div>
            </div>
        </div>

        <!-- Hiển thị danh sách phim -->
        <?php if (!$currentFolder && !$playLink): ?>
            <!-- Trang chủ: Hiển thị cả phần thêm phim -->
            <h1 class="text-4xl font-extrabold text-red-500 hover:text-red-400 transition-colors tracking-tight sm:text-5xl lg:text-6xl mb-10 text-shadow-md text-center">🎬 Movies Hub</h1>

            <!-- Thêm phim -->
            <div class="bg-slate-800 p-6 md:p-8 rounded-xl shadow-2xl mb-12">
                <h2 class="text-3xl font-bold text-sky-300 mb-6 text-shadow-sm">➕ Thêm phim mới</h2>

                <?php if ($successMessage): ?>
                    <div class="bg-emerald-500/90 text-white p-4 mb-6 rounded-lg shadow-md flex items-center space-x-3">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <span><?php echo $successMessage; ?></span>
                    </div>
                <?php endif; ?>
                <?php if ($errorMessage && !$playLink): ?>
                    <div class="bg-red-500/90 text-white p-4 mb-6 rounded-lg shadow-md flex items-center space-x-3">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <span><?php echo $errorMessage; ?></span>
                    </div>
                <?php endif; ?>

                <form method="POST" class="space-y-6" accept-charset="UTF-8">
                    <div>
                        <label class="block mb-2 text-sm font-medium text-slate-300">📁 Chọn thư mục đã có:</label>
                        <select name="selected_folder" class="block w-full p-3 rounded-lg bg-slate-700 border-slate-600 text-slate-100 placeholder-slate-400 focus:ring-2 focus:ring-sky-500 focus:border-sky-500 shadow-sm transition-colors duration-200">
                            <option value="">-- Chọn thư mục gốc hoặc để trống --</option>
                            <?php foreach ($existingFolders as $folder): ?>
                                <option value="<?php echo htmlspecialchars($folder); ?>"><?php echo htmlspecialchars($folder); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block mb-2 text-sm font-medium text-slate-300">📂 Hoặc nhập tên thư mục mới (ví dụ: PhimLe/HanhDong hoặc TenThuMuc):</label>
                        <input name="new_folder" placeholder="Tên thư mục mới (có thể chứa dấu / )" class="block w-full p-3 rounded-lg bg-slate-700 border-slate-600 text-slate-100 placeholder-slate-400 focus:ring-2 focus:ring-sky-500 focus:border-sky-500 shadow-sm transition-colors duration-200">
                    </div>
                    <div>
                        <label class="block mb-2 text-sm font-medium text-slate-300">🎞️ Tên phim:</label>
                        <input name="moviename" class="block w-full p-3 rounded-lg bg-slate-700 border-slate-600 text-slate-100 placeholder-slate-400 focus:ring-2 focus:ring-sky-500 focus:border-sky-500 shadow-sm transition-colors duration-200" required>
                    </div>
                    <div>
                        <label class="block mb-2 text-sm font-medium text-slate-300">🔗 Link phim (MP4, M3U8...):</label>
                        <input name="movielink" class="block w-full p-3 rounded-lg bg-slate-700 border-slate-600 text-slate-100 placeholder-slate-400 focus:ring-2 focus:ring-sky-500 focus:border-sky-500 shadow-sm transition-colors duration-200" required>
                    </div>
                    <div>
                        <label class="block mb-2 text-sm font-medium text-slate-300">🖼 Link thumbnail (tuỳ chọn):</label>
                        <input name="thumburl" placeholder="https://example.com/image.jpg" class="block w-full p-3 rounded-lg bg-slate-700 border-slate-600 text-slate-100 placeholder-slate-400 focus:ring-2 focus:ring-sky-500 focus:border-sky-500 shadow-sm transition-colors duration-200">
                    </div>
                    <button type="submit" class="w-full sm:w-auto bg-sky-600 hover:bg-sky-700 px-8 py-3 rounded-lg text-white font-semibold shadow-md hover:shadow-lg transition-all duration-200 ease-in-out transform hover:-translate-y-0.5 focus:outline-none focus:ring-2 focus:ring-sky-500 focus:ring-offset-2 focus:ring-offset-slate-800">Lưu phim</button>
                </form>
            </div>

            <h2 class="text-3xl font-bold text-sky-300 mb-8 text-shadow-sm">🗂️ Danh sách thư mục</h2>
            <?php if (!empty($movieList)): ?>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
                    <?php foreach ($movieList as $folder => $info): ?>
                        <a href="?folder=<?php echo urlencode($folder); ?>" class="bg-slate-800 p-6 rounded-xl shadow-lg hover:shadow-2xl transition-all duration-300 ease-in-out transform hover:-translate-y-1.5 group block">
                            <div class="flex items-center space-x-4">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-10 w-10 text-sky-400 group-hover:text-sky-300 transition-colors duration-200" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z" />
                                </svg>
                                <span class="text-xl font-semibold text-slate-100 group-hover:text-white transition-colors duration-200 truncate"><?php echo htmlspecialchars($folder); ?></span>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="text-slate-400 italic my-6 p-6 bg-slate-800 rounded-lg text-center shadow-md">Không có thư mục nào.</div>
            <?php endif; ?>

        <?php elseif (!$playLink): ?> <!-- Not on homepage, but not playing a video either (i.e., inside a folder) -->
            <!-- Khi vào thư mục: Chỉ hiển thị breadcrumb, thư mục con và phim -->
            <!-- Breadcrumb -->
            <div class="mb-6 text-sm text-slate-400">
                <?php
                $pathParts = array_filter(explode('/', $currentFolder));
                $breadcrumb = [];
                $currentPath = '';
                $breadcrumb[] = '<a href="play_link.php" class="text-sky-400 hover:text-sky-300 hover:underline transition-colors duration-150">Trang chủ</a>';
                foreach ($pathParts as $idx => $part) {
                    $currentPath = $currentPath ? "$currentPath/$part" : $part;
                    if ($idx < count($pathParts) - 1) {
                        $breadcrumb[] = '<a href="?folder=' . urlencode($currentPath) . '" class="text-sky-400 hover:text-sky-300 hover:underline transition-colors duration-150">' . htmlspecialchars($part) . '</a>';
                    } else {
                        $breadcrumb[] = '<span class="text-slate-200">' . htmlspecialchars($part) . '</span>'; // Current folder, not a link
                    }
                }
                echo implode(' <span class="mx-1 text-slate-500">></span> ', $breadcrumb);
                ?>
            </div>

            <?php if ($successMessage): ?>
                <div class="bg-emerald-500/90 text-white p-4 mb-6 rounded-lg shadow-md flex items-center space-x-3">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <span><?php echo $successMessage; ?></span>
                </div>
            <?php endif; ?>
            <?php if ($errorMessage && !$playLink): ?>
                <div class="bg-red-500/90 text-white p-4 mb-6 rounded-lg shadow-md flex items-center space-x-3">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <span><?php echo $errorMessage; ?></span>
                </div>
            <?php endif; ?>


            <!-- Hiển thị thư mục hiện tại và nút quay lại -->
            <div class="flex justify-between items-center mb-8">
                <h1 class="text-3xl font-bold text-sky-300 text-shadow-sm truncate"><?php echo htmlspecialchars(basename($currentFolder)); ?></h1>
                <a href="<?php echo $currentFolder ? '?folder=' . urlencode(implode('/', array_slice(explode('/', trim($currentFolder, '/')), 0, -1))) : 'play_link.php'; ?>" class="inline-flex items-center bg-slate-700 hover:bg-slate-600 text-slate-100 px-5 py-2.5 rounded-lg shadow-md hover:shadow-lg transition-all duration-200 ease-in-out transform hover:-translate-y-0.5">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                    </svg>
                    Quay lại
                </a>
            </div>

            <!-- Debug dữ liệu (có thể bỏ sau khi kiểm tra) -->
            <?php if (false): // Set to true to show debug, false to hide 
            ?>
                <pre class="bg-slate-700/50 p-4 rounded-lg mb-6 text-xs text-slate-300 overflow-auto shadow max-h-96">
            <?php echo "Current Folder: $currentFolder\n"; ?>
            <?php echo "Current Folder Data:\n"; ?>
            <?php print_r($currentFolderData); ?>
        </pre>
            <?php endif; ?>

            <!-- Chỉ hiển thị sub_folder -->
            <?php if (!empty($currentFolderData['sub']) && is_array($currentFolderData['sub'])): ?>
                <h4 class="text-2xl font-semibold text-sky-400 mb-4 mt-8 text-shadow-sm">📁 Thư mục con</h4>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6 mb-8">
                    <?php foreach ($currentFolderData['sub'] as $subFolder => $subInfo): ?>
                        <a href="?folder=<?php echo urlencode($currentFolder . '/' . $subFolder); ?>" class="bg-slate-800 p-5 rounded-xl shadow-lg hover:shadow-2xl transition-all duration-300 ease-in-out transform hover:-translate-y-1.5 group block">
                            <div class="flex items-center space-x-3">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8 text-sky-400 group-hover:text-sky-300 transition-colors duration-200" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z" />
                                </svg>
                                <span class="text-lg font-medium text-slate-200 group-hover:text-white transition-colors duration-200 truncate"><?php echo htmlspecialchars($subFolder); ?></span>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php elseif (empty($currentFolderData['movies'])): ?>
                <!-- Show "No subfolders" only if there are also no movies -->
                <div class="text-slate-400 italic my-6 p-6 bg-slate-800 rounded-lg text-center shadow-md">Không có thư mục con.</div>
            <?php endif; ?>


            <!-- Hiển thị danh sách phim -->
            <h3 class="text-xl sm:text-2xl font-semibold text-sky-400 mb-4 sm:mb-6 text-shadow-sm">🎥 Danh sách phim</h3>
            <?php if (!empty($currentFolderData['movies']) && is_array($currentFolderData['movies'])): ?>
                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-4 sm:gap-6 movie-grid">
                    <?php foreach ($currentFolderData['movies'] as $movie): ?>
                        <div class="bg-slate-800 rounded-xl shadow-lg overflow-hidden transition-all duration-300 ease-in-out transform hover:-translate-y-1 hover:shadow-2xl group">
                            <div class="relative w-full aspect-[3/2] sm:aspect-[5/4] bg-slate-700">
                                <?php
                                $ext = strtolower(pathinfo(parse_url($movie['link'], PHP_URL_PATH), PATHINFO_EXTENSION));
                                $isHls = $ext === 'm3u8';
                                ?>
                                <video id="video-<?php echo urlencode($movie['filePath']); ?>"
                                    class="absolute inset-0 w-full h-full object-cover cursor-pointer video-preview no-interaction"
                                    poster="<?php echo $movie['thumb'] ? htmlspecialchars($movie['thumb']) : 'https://via.placeholder.com/300x500/1e293b/94a3b8?text=No+Thumb'; ?>"
                                    muted
                                    playsinline
                                    data-src="<?php echo htmlspecialchars($movie['link']); ?>"
                                    data-is-hls="<?php echo $isHls ? 'true' : 'false'; ?>"
                                    data-file-path="<?php echo urlencode($movie['filePath']); ?>"
                                    data-play-url="?play=<?php echo urlencode($movie['link']); ?>&folder=<?php echo urlencode($currentFolder); ?>"
                                    aria-label="Preview phim <?php echo htmlspecialchars($movie['name']); ?>">
                                    <source type="<?php echo $isHls ? 'application/x-mpegURL' : 'video/mp4'; ?>">
                                    Trình duyệt không hỗ trợ video.
                                </video>
                            </div>
                            <div class="p-3 sm:p-4">
                                <p class="font-semibold text-slate-100 group-hover:text-sky-300 transition-colors duration-200 truncate mb-2 text-sm sm:text-base leading-tight">
                                    <a href="?play=<?php echo urlencode($movie['link']); ?>&folder=<?php echo urlencode($currentFolder); ?>" class="hover:underline"><?php echo htmlspecialchars($movie['name']); ?></a>
                                </p>
                                <div class="mt-2 sm:mt-3 flex justify-between items-center flex-wrap gap-2">
                                    <button onclick="confirmDelete('<?php echo urlencode($movie['filePath']); ?>', '<?php echo urlencode($currentFolder); ?>')" class="text-xs font-medium text-red-500 hover:text-red-400 transition-colors duration-150 hover:underline" aria-label="Xóa phim">Xoá</button>
                                    <?php if (empty($movie['thumb'])): ?>
                                        <button onclick="toggleThumbnailForm('<?php echo urlencode($movie['filePath']); ?>')" class="text-xs font-medium text-emerald-500 hover:text-emerald-400 transition-colors duration-150 hover:underline" aria-label="Thêm thumbnail">Add Thumbnail</button>
                                    <?php endif; ?>
                                </div>
                                <form id="form-<?php echo urlencode($movie['filePath']); ?>" method="POST" class="hidden mt-2 sm:mt-3 space-y-2 bg-slate-700/50 p-2 sm:p-3 rounded-md" accept-charset="UTF-8">
                                    <input type="hidden" name="file_path" value="<?php echo htmlspecialchars($movie['filePath'] ?? ''); ?>">
                                    <input type="hidden" name="add_thumbnail" value="1">
                                    <input name="thumb_url" placeholder="Link thumbnail..." class="w-full p-1.5 rounded bg-slate-600 border-slate-500 text-white text-xs placeholder-slate-400 focus:ring-1 focus:ring-sky-500 focus:border-sky-500">
                                    <button type="submit" class="w-full bg-emerald-600 hover:bg-emerald-700 px-3 py-1.5 rounded-md text-white text-xs font-semibold shadow-sm hover:shadow-md transition-all duration-150">Lưu Thumb</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="text-slate-400 italic my-4 p-4 sm:p-6 bg-slate-800 rounded-lg text-center shadow-md">Chưa có phim trong thư mục này.</div>
            <?php endif; ?>
        <?php endif; ?>

        <script>
            function toggleThumbnailForm(id) {
                const form = document.getElementById('form-' + id);
                if (form) {
                    form.classList.toggle('hidden');
                }
            }

            function confirmDelete(filePath, folder) {
                if (confirm('Bạn có chắc chắn muốn xóa phim này không?')) {
                    window.location.href = '?delete=' + filePath + '&folder=' + folder;
                }
            }

            // Video Preview on Hover
            let currentlyPlaying = null;
            let debounceTimeout = null;
            let previewTimeouts = [];

            function debounce(func, delay) {
                return function(...args) {
                    clearTimeout(debounceTimeout);
                    debounceTimeout = setTimeout(() => func.apply(this, args), delay);
                };
            }

            function startVideoPreview(videoElement) {
                if (currentlyPlaying && currentlyPlaying !== videoElement) {
                    stopVideoPreview(currentlyPlaying);
                }

                const videoSrc = videoElement.getAttribute('data-src');
                const isHls = videoElement.getAttribute('data-is-hls') === 'true';
                currentlyPlaying = videoElement;
                videoElement.classList.add('playing', 'selected'); // Add selected class

                if (!videoElement.dataset.loaded || videoElement.dataset.loaded === 'false') {
                    setupVideo(videoElement, videoSrc, isHls);
                    return;
                }

                videoElement.dataset.currentIndex = '0';
                videoElement.dataset.elapsedTime = '0';
                playPreviewSequence(videoElement);
            }

            function setupVideo(videoElement, videoSrc, isHls) {
                const setupPreview = (duration) => {
                    const safeDuration = isNaN(duration) || duration < 60 ? 600 : duration;
                    const previewPoints = [{
                            start: safeDuration * 0.20,
                            duration: 7500
                        },
                        {
                            start: safeDuration * 0.45,
                            duration: 12000
                        },
                        {
                            start: safeDuration * 0.70,
                            duration: 15000
                        }
                    ];
                    videoElement.dataset.previewPoints = JSON.stringify(previewPoints);
                    videoElement.dataset.currentIndex = '0';
                    videoElement.dataset.elapsedTime = '0';
                    videoElement.dataset.loaded = 'true';
                    playPreviewSequence(videoElement);
                };

                if (isHls && Hls.isSupported()) {
                    const hls = new Hls();
                    videoElement.hlsInstance = hls;
                    hls.loadSource(videoSrc);
                    hls.attachMedia(videoElement);
                    hls.on(Hls.Events.MANIFEST_PARSED, function(event, data) {
                        let duration = videoElement.duration;
                        if (isNaN(duration) || duration <= 0) {
                            duration = data.levels?.[0]?.details?.totalduration || data.totalduration || 600;
                        }
                        setupPreview(duration);
                    });
                    hls.on(Hls.Events.ERROR, function(event, data) {
                        console.error('HLS error:', data);
                        videoElement.dataset.loaded = 'true';
                        setupPreview(600);
                    });
                } else if (!isHls) {
                    let sourceTag = videoElement.querySelector('source');
                    if (!sourceTag) {
                        sourceTag = document.createElement('source');
                        videoElement.appendChild(sourceTag);
                    }
                    sourceTag.src = videoSrc;
                    sourceTag.type = 'video/mp4';

                    videoElement.load();
                    videoElement.onloadedmetadata = function() {
                        setupPreview(videoElement.duration || 600);
                    };
                    videoElement.onerror = function() {
                        console.error('Error loading MP4 video preview:', videoSrc, videoElement.error);
                        videoElement.dataset.loaded = 'true';
                        setupPreview(600);
                    };
                } else {
                    console.warn('HLS not supported or invalid video format for preview:', videoSrc);
                    videoElement.dataset.loaded = 'true';
                    setupPreview(600);
                }
            }

            function playPreviewSequence(videoElement) {
                if (videoElement !== currentlyPlaying) return;

                const points = JSON.parse(videoElement.dataset.previewPoints);
                let currentIndex = parseInt(videoElement.dataset.currentIndex, 10);

                if (currentIndex >= points.length) {
                    currentIndex = 0;
                }

                const segment = points[currentIndex];
                videoElement.currentTime = segment.start;

                videoElement.play().catch(error => {
                    // console.warn('Error playing video preview segment:', error);
                    if (videoElement === currentlyPlaying) currentlyPlaying = null;
                });

                const timeoutId = setTimeout(() => {
                    if (videoElement === currentlyPlaying) {
                        videoElement.pause();
                        videoElement.dataset.currentIndex = (currentIndex + 1).toString();
                        playPreviewSequence(videoElement);
                    }
                }, segment.duration);
                previewTimeouts.push(timeoutId);
            }

            function stopVideoPreview(videoElement) {
                videoElement.pause();
                videoElement.currentTime = 0;
                videoElement.classList.remove('playing', 'selected'); // Remove selected class

                previewTimeouts.forEach(timeoutId => clearTimeout(timeoutId));
                previewTimeouts = [];

                if (videoElement.hlsInstance) {
                    videoElement.hlsInstance.destroy();
                    videoElement.hlsInstance = null;
                }

                videoElement.dataset.loaded = 'false';
                if (videoElement === currentlyPlaying) {
                    currentlyPlaying = null;
                }
            }

            const debouncedStartPreview = debounce(startVideoPreview, 250);

            // Mobile touch handling
            document.querySelectorAll('.video-preview').forEach(video => {
                let touchTimeout;
                let isHolding = false;
                let startX, startY;
                let hasMoved = false;
                const moveThreshold = 10; // Pixels to consider as a swipe

                video.addEventListener('touchstart', function(e) {
                    // Do not prevent default here to allow scrolling
                    isHolding = false;
                    hasMoved = false;
                    startX = e.touches[0].clientX;
                    startY = e.touches[0].clientY;

                    touchTimeout = setTimeout(() => {
                        if (!hasMoved) {
                            isHolding = true;
                            debouncedStartPreview(video);
                        }
                    }, 300); // Long-press after 300ms
                });

                video.addEventListener('touchmove', function(e) {
                    if (isHolding) {
                        const touch = e.touches[0];
                        const elementUnderFinger = document.elementFromPoint(touch.clientX, touch.clientY);
                        if (elementUnderFinger && elementUnderFinger.classList.contains('video-preview') && elementUnderFinger !== video) {
                            // Stop current preview
                            stopVideoPreview(video);
                            // Start preview for the new video
                            debouncedStartPreview(elementUnderFinger);
                        }
                    }
                });
                video.addEventListener('touchend', function(e) {
                    clearTimeout(touchTimeout);
                    if (!hasMoved && !isHolding) {
                        // Short tap: navigate to play page
                        window.location.href = video.getAttribute('data-play-url');
                    }
                    if (isHolding) {
                        // Stop preview if it was playing
                        stopVideoPreview(video);
                        isHolding = false;
                    }
                });

                // Desktop hover handling (unchanged)
                video.addEventListener('mouseover', function() {
                    debouncedStartPreview(video);
                });

                video.addEventListener('mouseout', function() {
                    stopVideoPreview(video);
                });
            });
        </script>

        <!-- Trình phát -->
        <?php if ($validLink): ?>
            <div class="fixed inset-0 bg-slate-900/95 backdrop-blur-md z-50 flex flex-col items-center justify-center p-2 sm:p-4">
                <div class="w-full max-w-4xl bg-slate-800 rounded-xl shadow-2xl overflow-hidden">
                    <div class="p-3 sm:p-4 flex justify-between items-center border-b border-slate-700">
                        <h2 class="text-lg sm:text-xl font-bold text-sky-300 truncate">▶️ Đang phát phim</h2>
                        <a href="?folder=<?php echo urlencode($currentFolder); ?>" class="inline-flex items-center bg-slate-700 hover:bg-slate-600 text-slate-100 px-3 sm:px-4 py-1.5 sm:py-2 rounded-lg shadow-md hover:shadow-lg transition-all duration-200 ease-in-out text-xs sm:text-sm">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-3 sm:h-4 w-3 sm:w-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                            </svg>
                            Quay về
                        </a>
                    </div>
                    <div class="aspect-video bg-black">
                        <?php if ($linkType === 'hls'): ?>
                            <video id="videoPlayer" class="w-full h-full" controls autoplay></video>
                            <script>
                                if (Hls.isSupported()) {
                                    const hlsPlayer = new Hls();
                                    hlsPlayer.loadSource(<?php echo json_encode($activeLink); ?>);
                                    hlsPlayer.attachMedia(document.getElementById('videoPlayer'));
                                    document.getElementById('videoPlayer').hlsInstance = hlsPlayer;
                                    window.addEventListener('unload', () => {
                                        if (hlsPlayer) hlsPlayer.destroy();
                                    });
                                } else if (document.getElementById('videoPlayer').canPlayType('application/vnd.apple.mpegurl')) {
                                    document.getElementById('videoPlayer').src = <?php echo json_encode($activeLink); ?>;
                                }
                            </script>
                        <?php else: ?>
                            <video id="videoPlayer" class="w-full h-full" controls autoplay playsinline webkit-playsinline>
                                <source src="<?php echo htmlspecialchars($activeLink); ?>" type="video/mp4">
                                Trình duyệt không hỗ trợ video.
                            </video>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</body>

</html>