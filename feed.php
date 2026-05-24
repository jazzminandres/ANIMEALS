<?php
// THIS FILE SHOWS THE STUDENT FEED, LOADS POSTS, SORTS THEM, AND HANDLES POST IMAGE PREVIEWS.
require_once __DIR__ . '/session_config.php';
session_start();
require_once __DIR__ . '/db.php';

if (!isset($_SESSION['user'])) {
    header('Location: index.php');
    exit();
}

// Use centralized DB helper
$conn = db_connect(DB_NAME_ANIMEALS);
$connPosts = db_connect(DB_NAME_ANIMEALS_POSTS);

// Ensure extensions
require_once __DIR__ . '/schema_bootstrap.php';
animeals_posts_ensure_extensions($connPosts);

$stmt = db_query($conn, "SELECT * FROM user_details WHERE userEMAIL = ?", [$_SESSION['email']]);
if ($stmt === false || !($user = db_fetch_assoc($stmt))) {
    session_destroy();
    header('Location: index.php');
    exit();
}

$roleKey = strtolower(trim((string) ($user['userROLE'] ?? 'student')));
$backPage = match ($roleKey) {
    'seller' => 'seller.php',
    'admin' => 'admin.php',
    default => 'student.php',
};

$profilePic = !empty($user['userPROFILEPIC'])
    ? htmlspecialchars($user['userPROFILEPIC'])
    : 'https://cdn-icons-png.flaticon.com/512/149/149071.png';

$sort = $_GET['sort'] ?? 'newest';
if (!in_array($sort, ['newest', 'oldest', 'likes'], true)) {
    $sort = 'newest';
}
$orderBy = match ($sort) {
    'oldest' => 'p.postDATE ASC',
    'likes' => 'likeCount DESC, p.postDATE DESC',
    default => 'p.postDATE DESC',
};

$focusPostId = (int) ($_GET['post'] ?? 0);

$posts = [];
if ($connPosts) {
    if ($focusPostId > 0) {
        $pStmt = db_query($connPosts,
            "SELECT p.*, u.userNAME AS authorName, u.userPROFILEPIC AS authorPic,
                    COALESCE(pl.likeCount, 0) AS likeCount
             FROM posts p
             LEFT JOIN animeals.user_details u ON u.userEMAIL = p.userEMAIL
             LEFT JOIN (
                SELECT postID, COUNT(*) AS likeCount
                FROM post_likes
                GROUP BY postID
             ) pl ON pl.postID = p.postID
             WHERE p.postID = ?",
            [$focusPostId]
        );
    } else {
        $pStmt = db_query($connPosts,
            "SELECT p.*, u.userNAME AS authorName, u.userPROFILEPIC AS authorPic,
                    COALESCE(pl.likeCount, 0) AS likeCount
             FROM posts p
             LEFT JOIN animeals.user_details u ON u.userEMAIL = p.userEMAIL
             LEFT JOIN (
                SELECT postID, COUNT(*) AS likeCount
                FROM post_likes
                GROUP BY postID
             ) pl ON pl.postID = p.postID
             ORDER BY " . $orderBy
        );
    }
    $posts = $pStmt ? db_fetch_all($pStmt) : [];
}

$postIds = array_map(static fn ($p) => (int) ($p['postID'] ?? 0), $posts);
$postIds = array_values(array_filter($postIds));

$commentsByPost = [];
$likedCommentIds = [];
$userLikedPosts = [];
$imagesByPost = [];

if ($connPosts && $postIds !== []) {
    $inList = implode(',', $postIds);
    $imgStmt = db_query($connPosts,
        "SELECT postID, imagePath
         FROM post_images
         WHERE postID IN ($inList)
         ORDER BY postID ASC, displayOrder ASC, imageID ASC"
    );
    $imageRows = $imgStmt ? db_fetch_all($imgStmt) : [];
    foreach ($imageRows as $imageRow) {
        $pid = (int) ($imageRow['postID'] ?? 0);
        $path = trim((string) ($imageRow['imagePath'] ?? ''));
        if ($pid > 0 && $path !== '') {
            $imagesByPost[$pid][] = $path;
        }
    }

    $cStmt = db_query($connPosts,
        "SELECT c.*, u.userNAME, u.userPROFILEPIC
         FROM comments c
         JOIN animeals.user_details u ON c.userEMAIL = u.userEMAIL
         WHERE c.postID IN ($inList)
         ORDER BY c.postID ASC, c.commentDATE ASC"
    );
    $comments = $cStmt ? db_fetch_all($cStmt) : [];
    foreach ($comments as $c) {
        $pid = (int) $c['postID'];
        $commentsByPost[$pid][] = $c;
    }

    $lk = db_query($connPosts,
        "SELECT cl.commentID FROM comment_likes cl
         INNER JOIN comments c ON c.commentID = cl.commentID
         WHERE cl.userEMAIL = ? AND c.postID IN ($inList)",
        [$_SESSION['email']]
    );
    $likes = $lk ? db_fetch_all($lk) : [];
    foreach ($likes as $r) {
        $likedCommentIds[(int) $r['commentID']] = true;
    }

    $pl = db_query($connPosts,
        "SELECT postID FROM post_likes WHERE userEMAIL = ? AND postID IN ($inList)",
        [$_SESSION['email']]
    );
    $pls = $pl ? db_fetch_all($pl) : [];
    foreach ($pls as $r) {
        $userLikedPosts[(int) $r['postID']] = true;
    }
}

foreach ($posts as &$prow) {
    $pid = (int) ($prow['postID'] ?? 0);
    $clist = $commentsByPost[$pid] ?? [];
    foreach ($clist as &$com) {
        $com['userLikedComment'] = !empty($likedCommentIds[(int) ($com['commentID'] ?? 0)]);
    }
    unset($com);
    $prow['comments'] = $clist;
    $prow['userLiked'] = !empty($userLikedPosts[$pid]);
    $legacyImage = trim((string) ($prow['postIMAGE'] ?? ''));
    $prow['postIMAGES'] = $imagesByPost[$pid] ?? ($legacyImage !== '' ? [$legacyImage] : []);
}
unset($prow);

$pageTitle = $focusPostId > 0 ? 'Post' : 'Food feed';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ANIMEALS | <?= htmlspecialchars($pageTitle) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --animeals-bg: #f3f7f5;
            --animeals-card: #ffffff;
            --animeals-green: #1dbf73;
            --animeals-green-dark: #0f7b55;
            --animeals-text: #25332d;
            --animeals-muted: #66746e;
            --animeals-line: #e7eeea;
            --animeals-soft: #f1f4f3;
            --animeals-shadow: 0 10px 25px rgba(0,0,0,0.08);
            --animeals-shadow-soft: 0 5px 15px rgba(0,0,0,0.05);
        }
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Poppins', sans-serif; }
        body { background: var(--animeals-bg); min-height: 100vh; color: var(--animeals-text); }
        .feed-top {
            background: linear-gradient(135deg, var(--animeals-green-dark), var(--animeals-green));
            color: #fff;
            padding: 18px 20px 24px;
            display: flex;
            align-items: center;
            gap: 14px;
            flex-wrap: wrap;
            box-shadow: var(--animeals-shadow);
        }
        .back-feed {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: #fff;
            text-decoration: none;
            font-weight: 700;
            font-size: 15px;
            cursor: pointer;
            border: none;
            background: rgba(255,255,255,0.18);
            padding: 10px 16px;
            border-radius: 18px;
        }
        .back-feed:hover { background: rgba(255,255,255,0.3); }
        .feed-top h1 { font-size: 20px; font-weight: 800; flex: 1; min-width: 140px; }
        .sort-row { display: flex; align-items: center; gap: 10px; font-size: 13px; font-weight: 600; }
        .sort-row select {
            padding: 8px 12px;
            border-radius: 18px;
            border: none;
            font-weight: 600;
            color: var(--animeals-text);
            background: #fff;
        }
        .container { max-width: 640px; margin: auto; padding: 20px 16px 40px; }
        .post {
            background: var(--animeals-card);
            border-radius: 25px;
            padding: 18px;
            margin-bottom: 18px;
            box-shadow: var(--animeals-shadow-soft);
        }
        .post-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; }
        .post-user-info { display: flex; align-items: center; gap: 12px; }
        .post-user-info img { width: 48px; height: 48px; border-radius: 50%; object-fit: cover; border: 3px solid #fff; box-shadow: 0 5px 15px rgba(0,0,0,0.12); }
        .user-details b { display: block; font-size: 15px; color: var(--animeals-text); }
        .user-details small { color: var(--animeals-muted); font-size: 12px; }
        .post-content p { font-size: 14px; color: var(--animeals-text); line-height: 1.5; margin-bottom: 8px; }
        .post-content img { cursor: zoom-in; }
        .post-content > img { width: 100%; border-radius: 15px; margin-top: 6px; max-height: 420px; object-fit: cover; }
        .post-image-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
            gap: 8px;
            margin-top: 8px;
        }
        .post-image-grid img {
            width: 100%;
            aspect-ratio: 1 / 1;
            border-radius: 15px;
            object-fit: cover;
        }
        .post-meta { font-size: 12px; color: var(--animeals-muted); margin: 10px 0 8px; display: flex; gap: 16px; flex-wrap: wrap; }
        .post-actions { display: flex; gap: 16px; flex-wrap: wrap; margin-top: 8px; }
        .action-btn {
            background: none; border: none; cursor: pointer; font-size: 14px; color: var(--animeals-muted);
            display: flex; align-items: center; gap: 6px; font-family: inherit;
        }
        .action-btn.liked { color: #e74c3c; }
        .action-btn:hover, .comment-reply-btn:hover { color: var(--animeals-green-dark); }
        .comment-section { margin-top: 14px; border-top: 1px solid var(--animeals-line); padding-top: 12px; }
        .comment-item { display: flex; gap: 10px; margin-bottom: 12px; align-items: flex-start; }
        .comment-item img { width: 32px; height: 32px; border-radius: 50%; object-fit: cover; flex-shrink: 0; }
        .comment-bubble { background: var(--animeals-soft); padding: 8px 12px; border-radius: 14px; flex: 1; }
        .comment-bubble b { font-size: 13px; display: block; }
        .comment-bubble p { font-size: 13px; color: var(--animeals-text); margin: 4px 0; }
        .comment-bubble small { color: var(--animeals-muted); font-size: 11px; }
        .comment-actions { display: flex; gap: 12px; margin-top: 4px; }
        .comment-like-btn, .comment-reply-btn {
            background: none; border: none; cursor: pointer; font-size: 12px; color: var(--animeals-muted);
            display: flex; align-items: center; gap: 4px; font-family: inherit;
        }
        .comment-like-btn.liked { color: #e74c3c; }
        .reply-form { display: none; gap: 8px; margin-top: 8px; align-items: center; }
        .reply-form input {
            flex: 1; padding: 8px 12px; border-radius: 14px; border: 1px solid var(--animeals-line); font-size: 13px;
            background: #fff;
        }
        .reply-form button, .comment-form button {
            background: linear-gradient(135deg, var(--animeals-green-dark), var(--animeals-green));
            color: #fff; border: none; padding: 8px 14px; border-radius: 14px;
            font-weight: 700; cursor: pointer; font-size: 12px;
        }
        .comment-form { display: flex; gap: 10px; align-items: center; margin-top: 12px; }
        .comment-form input {
            flex: 1; padding: 10px 14px; border-radius: 18px; border: 1px solid var(--animeals-line); font-size: 13px;
            background: var(--animeals-soft);
        }
        .empty-feed { text-align: center; color: var(--animeals-muted); padding: 40px 16px; font-size: 14px; }
        .view-all { display: inline-block; margin-bottom: 16px; color: var(--animeals-green-dark); font-weight: 700; text-decoration: none; }
        .post-image-viewer {
            position: fixed;
            inset: 0;
            display: none;
            z-index: 3000;
            background: rgba(0,0,0,0.88);
            overflow: auto;
            padding: 72px 18px 32px;
        }
        .post-image-viewer.open { display: flex; align-items: flex-start; justify-content: center; }
        .post-image-viewer img {
            width: auto;
            max-width: min(96vw, 1100px);
            height: auto;
            min-height: 0;
            border-radius: 16px;
            box-shadow: 0 24px 70px rgba(0,0,0,0.45);
            cursor: default;
        }
        .post-image-viewer-close {
            position: fixed;
            top: 18px;
            right: 18px;
            width: 42px;
            height: 42px;
            border: none;
            border-radius: 50%;
            background: rgba(255,255,255,0.95);
            color: #111;
            font-size: 28px;
            line-height: 1;
            cursor: pointer;
            z-index: 3001;
        }
    </style>
</head>
<body>

<div id="postImageViewer" class="post-image-viewer" onclick="feedCloseImageViewer(event)" aria-hidden="true">
    <button type="button" class="post-image-viewer-close" onclick="feedCloseImageViewer(event)" aria-label="Close">&times;</button>
    <img id="postImageViewerImg" src="" alt="Post preview" onclick="event.stopPropagation()">
</div>

<div class="feed-top">
    <a class="back-feed" href="<?= htmlspecialchars($backPage) ?>"><i class="bi bi-arrow-left"></i> Dashboard</a>
    <h1><?= $focusPostId > 0 ? 'Post' : 'Food feed' ?></h1>
    <?php if ($focusPostId <= 0): ?>
    <div class="sort-row">
        <label for="feedSort">Sort</label>
        <select id="feedSort" onchange="location.href='feed.php?sort='+encodeURIComponent(this.value)">
            <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>Newest</option>
            <option value="oldest" <?= $sort === 'oldest' ? 'selected' : '' ?>>Oldest</option>
            <option value="likes" <?= $sort === 'likes' ? 'selected' : '' ?>>Most likes</option>
        </select>
    </div>
    <?php endif; ?>
</div>

<div class="container">
    <?php if ($focusPostId > 0): ?>
        <a class="view-all" href="feed.php"><i class="bi bi-grid-fill"></i> View all posts</a>
    <?php endif; ?>

    <?php if (empty($posts)): ?>
        <div class="empty-feed">No posts to show yet.</div>
    <?php else: ?>
        <?php foreach ($posts as $post):
            $pid = (int) $post['postID'];
            $rawAuthor = $post['authorName'] ?? $post['authorname'] ?? '';
            $authorName = htmlspecialchars(trim((string) ($rawAuthor !== '' ? $rawAuthor : ($post['userEMAIL'] ?? 'User'))));
            $authorPic = !empty($post['authorPic'])
                ? htmlspecialchars((string) $post['authorPic'])
                : 'https://ui-avatars.com/api/?name=' . rawurlencode($authorName) . '&background=1dbf73&color=fff';
            $pDate = $post['postDATE'] instanceof DateTimeInterface ? $post['postDATE']->format('M j, Y \a\t g:i A') : '';
            $shares = (int) ($post['postSHARES'] ?? 0);
            $comments = $post['comments'] ?? [];
            $postImages = array_values($post['postIMAGES'] ?? []);
            $visibleLimit = 4;
        ?>
        <div class="post" id="post-<?= $pid ?>">
            <div class="post-header">
                <div class="post-user-info">
                    <img src="<?= $authorPic ?>" alt="" onerror="this.src='https://ui-avatars.com/api/?name=U&background=1dbf73&color=fff'">
                    <div class="user-details">
                        <b><?= $authorName ?></b>
                        <small><?= htmlspecialchars($pDate) ?></small>
                    </div>
                </div>
            </div>
            <div class="post-content">
                <?php if (!empty($post['postCONTENT'])): ?>
                    <p><?= nl2br(htmlspecialchars((string) $post['postCONTENT'])) ?></p>
                <?php endif; ?>
                <?php if ($postImages !== []): ?>
                    <div class="post-image-grid">
                        <?php foreach ($postImages as $imagePath): ?>
                            <img src="<?= htmlspecialchars((string) $imagePath) ?>" alt="Post">
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <?php if (!empty($post['postLAT']) && !empty($post['postLNG'])): ?>
                    <div style="margin-top: 10px;">
                        <iframe src="https://www.google.com/maps/embed/v1/place?key=AIzaSyACss1f2TEk4tUgZXWtjJi-pxkL9dLhDqw&q=<?= $post['postLAT'] ?>,<?= $post['postLNG'] ?>" 
                                style="width:100%; height:180px; border-radius:10px; border:0;" allowfullscreen></iframe>
                        <?php if (!empty($post['postADDRESS'])): ?>
                            <p style="font-size: 12px; color: #666; margin-top: 5px; text-align: center;">
                                <i class="bi bi-geo-alt-fill"></i> <?= htmlspecialchars($post['postADDRESS']) ?>
                            </p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="post-meta">
                <span id="likeCount-<?= $pid ?>"><?= (int) ($post['likeCount'] ?? $post['postLIKES'] ?? 0) ?> likes</span>
                <span id="commentCount-<?= $pid ?>"><?= count($comments) ?> comments</span>
                <span id="shareCount-<?= $pid ?>"><?= $shares ?> shares</span>
            </div>
            <div class="post-actions">
                <button type="button" class="action-btn <?= !empty($post['userLiked']) ? 'liked' : '' ?>"
                        id="likeBtn-<?= $pid ?>" onclick="feedToggleLike(<?= $pid ?>)">
                    <i class="bi <?= !empty($post['userLiked']) ? 'bi-heart-fill' : 'bi-heart' ?>"></i> Like
                </button>
                <button type="button" class="action-btn" onclick="document.getElementById('commentInput-<?= $pid ?>').focus()">
                    <i class="bi bi-chat"></i> Comment
                </button>
                <button type="button" class="action-btn" onclick="feedShare(<?= $pid ?>)">
                    <i class="bi bi-share"></i> Share
                </button>
            </div>
            <div class="comment-section" id="comments-<?= $pid ?>">
                <div id="commentList-<?= $pid ?>">
                    <?php foreach ($comments as $i => $comment):
                        $hidden = $i >= $visibleLimit ? 'style="display:none"' : '';
                        $cid = (int) $comment['commentID'];
                        $cPic = !empty($comment['userPROFILEPIC'])
                            ? htmlspecialchars((string) $comment['userPROFILEPIC'])
                            : 'https://cdn-icons-png.flaticon.com/512/149/149071.png';
                        $cDate = $comment['commentDATE'] instanceof DateTimeInterface
                            ? $comment['commentDATE']->format('M j \a\t g:i A') : '';
                    ?>
                    <div class="comment-item" id="comment-<?= $cid ?>" <?= $hidden ?> data-comment-index="<?= $i ?>">
                        <img src="<?= $cPic ?>" alt="">
                        <div style="flex:1;">
                            <div class="comment-bubble">
                                <b><?= htmlspecialchars((string) ($comment['userNAME'] ?? 'User')) ?></b>
                                <p><?= nl2br(htmlspecialchars((string) ($comment['commentCONTENT'] ?? ''))) ?></p>
                                <small><?= htmlspecialchars($cDate) ?></small>
                            </div>
                            <div class="comment-actions">
                                <button type="button" class="comment-like-btn <?= !empty($comment['userLikedComment']) ? 'liked' : '' ?>"
                                        id="cLikeBtn-<?= $cid ?>" onclick="feedToggleCommentLike(<?= $cid ?>)">
                                    <i class="bi <?= !empty($comment['userLikedComment']) ? 'bi-heart-fill' : 'bi-heart' ?>"></i>
                                    <span id="cLikeCount-<?= $cid ?>"><?= (int) ($comment['commentLIKES'] ?? 0) ?></span>
                                </button>
                                <button type="button" class="comment-reply-btn" onclick="feedToggleReply(<?= $cid ?>)">
                                    <i class="bi bi-reply"></i> Reply
                                </button>
                            </div>
                            <div class="reply-form" id="replyForm-<?= $cid ?>">
                                <img src="<?= $profilePic ?>" style="width:26px;height:26px;border-radius:50%;object-fit:cover;" alt="">
                                <input type="text" id="replyInput-<?= $cid ?>" placeholder="Write a reply…">
                                <button type="button" onclick="feedSubmitReply(<?= $cid ?>, <?= $pid ?>)">Reply</button>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php if (count($comments) > $visibleLimit): ?>
                <button type="button" class="action-btn" style="margin:8px 0;" id="showMoreBtn-<?= $pid ?>"
                        onclick="feedShowMoreComments(<?= $pid ?>, <?= count($comments) ?>, <?= $visibleLimit ?>)">
                    Show <?= count($comments) - $visibleLimit ?> more comments
                </button>
                <?php endif; ?>
                <div class="comment-form">
                    <img src="<?= $profilePic ?>" style="width:32px;height:32px;border-radius:50%;object-fit:cover;" alt="">
                    <input type="text" id="commentInput-<?= $pid ?>" placeholder="Write a comment…"
                           onkeydown="if(event.key==='Enter'){event.preventDefault();feedSubmitComment(<?= $pid ?>);}">
                    <button type="button" onclick="feedSubmitComment(<?= $pid ?>)">Send</button>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<script>
    const FEED_ME = { name: <?= json_encode($user['userNAME'] ?? '') ?>, pic: <?= json_encode($profilePic) ?> };

    function feedOpenImageViewer(src) {
        if (!src) return;
        const viewer = document.getElementById('postImageViewer');
        const img = document.getElementById('postImageViewerImg');
        img.src = src;
        viewer.classList.add('open');
        viewer.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
    }

    function feedCloseImageViewer(event) {
        if (event) event.stopPropagation();
        const viewer = document.getElementById('postImageViewer');
        const img = document.getElementById('postImageViewerImg');
        viewer.classList.remove('open');
        viewer.setAttribute('aria-hidden', 'true');
        img.src = '';
        document.body.style.overflow = '';
    }

    document.addEventListener('click', function (event) {
        const img = event.target.closest('.post-content img');
        if (!img) return;
        event.preventDefault();
        feedOpenImageViewer(img.currentSrc || img.src);
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') feedCloseImageViewer(event);
    });

    async function feedToggleLike(postID) {
        const fd = new FormData();
        fd.append('action', 'toggleLike');
        fd.append('postID', postID);
        const res = await fetch('profile.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (!data.success) return;
        const btn = document.getElementById('likeBtn-' + postID);
        const count = document.getElementById('likeCount-' + postID);
        const icon = btn.querySelector('i');
        if (data.liked) {
            btn.classList.add('liked');
            icon.className = 'bi bi-heart-fill';
        } else {
            btn.classList.remove('liked');
            icon.className = 'bi bi-heart';
        }
        count.textContent = data.likes + ' likes';
    }

    async function feedSubmitComment(postID) {
        const input = document.getElementById('commentInput-' + postID);
        const content = (input.value || '').trim();
        if (!content) return;
        const fd = new FormData();
        fd.append('action', 'submitComment');
        fd.append('postID', postID);
        fd.append('commentContent', content);
        const res = await fetch('profile.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (!data.success) return;
        input.value = '';
        const list = document.getElementById('commentList-' + postID);
        list.insertAdjacentHTML('beforeend', feedBuildCommentHTML(data, postID));
        const countEl = document.getElementById('commentCount-' + postID);
        const n = (parseInt(countEl.textContent, 10) || 0) + 1;
        countEl.textContent = n + ' comments';
    }

    function feedEscapeHtml(str) {
        const d = document.createElement('div');
        d.textContent = str == null ? '' : String(str);
        return d.innerHTML;
    }

    function feedBuildCommentHTML(data, postID) {
        return '<div class="comment-item" id="comment-' + data.commentID + '">' +
            '<img src="' + feedEscapeHtml(data.userPROFILEPIC) + '" alt="">' +
            '<div style="flex:1;"><div class="comment-bubble">' +
            '<b>' + feedEscapeHtml(data.userNAME) + '</b>' +
            '<p>' + feedEscapeHtml(data.commentCONTENT) + '</p>' +
            '<small>' + feedEscapeHtml(data.commentDATE) + '</small></div>' +
            '<div class="comment-actions">' +
            '<button type="button" class="comment-like-btn" id="cLikeBtn-' + data.commentID + '" onclick="feedToggleCommentLike(' + data.commentID + ')">' +
            '<i class="bi bi-heart"></i> <span id="cLikeCount-' + data.commentID + '">0</span></button>' +
            '<button type="button" class="comment-reply-btn" onclick="feedToggleReply(' + data.commentID + ')">' +
            '<i class="bi bi-reply"></i> Reply</button></div>' +
            '<div class="reply-form" id="replyForm-' + data.commentID + '">' +
            '<img src="' + feedEscapeHtml(FEED_ME.pic) + '" style="width:26px;height:26px;border-radius:50%;object-fit:cover;" alt="">' +
            '<input type="text" id="replyInput-' + data.commentID + '" placeholder="Write a reply…">' +
            '<button type="button" onclick="feedSubmitReply(' + data.commentID + ', ' + postID + ')">Reply</button></div></div></div>';
    }

    async function feedToggleCommentLike(commentID) {
        const fd = new FormData();
        fd.append('action', 'toggleCommentLike');
        fd.append('commentID', commentID);
        const res = await fetch('profile.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (!data.success) return;
        const btn = document.getElementById('cLikeBtn-' + commentID);
        const count = document.getElementById('cLikeCount-' + commentID);
        const icon = btn.querySelector('i');
        if (data.liked) {
            btn.classList.add('liked');
            icon.className = 'bi bi-heart-fill';
        } else {
            btn.classList.remove('liked');
            icon.className = 'bi bi-heart';
        }
        count.textContent = data.likes;
    }

    function feedToggleReply(commentID) {
        const form = document.getElementById('replyForm-' + commentID);
        const show = form.style.display !== 'flex';
        form.style.display = show ? 'flex' : 'none';
        if (show) document.getElementById('replyInput-' + commentID).focus();
    }

    async function feedSubmitReply(commentID, postID) {
        const input = document.getElementById('replyInput-' + commentID);
        const content = (input.value || '').trim();
        if (!content) return;
        const fd = new FormData();
        fd.append('action', 'submitComment');
        fd.append('postID', postID);
        fd.append('commentContent', '↩ ' + content);
        const res = await fetch('profile.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (!data.success) return;
        input.value = '';
        document.getElementById('replyForm-' + commentID).style.display = 'none';
        const list = document.getElementById('commentList-' + postID);
        list.insertAdjacentHTML('beforeend', feedBuildCommentHTML(data, postID));
        const countEl = document.getElementById('commentCount-' + postID);
        const n = (parseInt(countEl.textContent, 10) || 0) + 1;
        countEl.textContent = n + ' comments';
    }

    function feedShowMoreComments(postID, total, limit) {
        const list = document.getElementById('commentList-' + postID);
        const items = list.querySelectorAll('.comment-item');
        const btn = document.getElementById('showMoreBtn-' + postID);
        let hidden = 0;
        items.forEach(function (el) {
            if (el.style.display === 'none') {
                el.style.display = '';
                hidden++;
            }
        });
        if (btn) {
            if (hidden === 0 && items.length > limit) {
                items.forEach(function (el, idx) {
                    if (idx >= limit) el.style.display = 'none';
                });
                btn.textContent = 'Show ' + (total - limit) + ' more comments';
            } else {
                btn.style.display = 'none';
            }
        }
    }

    async function feedShare(postID) {
        const url = new URL('feed.php?post=' + postID, window.location.href).href;
        try {
            await navigator.clipboard.writeText(url);
        } catch (e) {}
        const fd = new FormData();
        fd.append('action', 'incrementShare');
        fd.append('postID', postID);
        const res = await fetch('profile.php', { method: 'POST', body: fd });
        let shares = null;
        try {
            const data = await res.json();
            if (data.success && data.shares != null) shares = data.shares;
        } catch (e) {}
        const el = document.getElementById('shareCount-' + postID);
        if (el && shares != null) {
            el.textContent = shares + ' shares';
        } else if (el) {
            const t = (el.textContent || '').trim();
            const m = t.match(/^(\d+)/);
            const n = m ? parseInt(m[1], 10) + 1 : 1;
            el.textContent = n + ' shares';
        }
        alert('Link to this post copied. Paste it anywhere to share.');
    }
</script>
</body>
</html>
