<?php
declare(strict_types=1);

/**
 * NuxtWiki API 前端控制器
 * 路由规则：index.php?r=<controller>.<action>
 * 所有接口默认返回 JSON（RSS / 附件下载除外）。
 */

require __DIR__ . '/bootstrap.php';

// ---------- 路由表 ----------
// 认证
Router::add('GET',  'auth.me',       AuthController::class, 'me');
Router::add('GET',  'auth.csrf',     AuthController::class, 'csrf');
Router::add('POST', 'auth.login',    AuthController::class, 'login');
Router::add('POST', 'auth.register', AuthController::class, 'register');
Router::add('POST', 'auth.logout',   AuthController::class, 'logout');

// 用户（管理）
Router::add('GET',  'users.list',   UserController::class, 'list');
Router::add('POST', 'users.create', UserController::class, 'create');
Router::add('POST', 'users.update', UserController::class, 'update');
Router::add('POST', 'users.set-status', UserController::class, 'setStatus');
Router::add('POST', 'users.delete', UserController::class, 'delete');

// 用户（个人资料）
Router::add('GET',  'user.me',            UserController::class, 'me');
Router::add('GET',  'user.profile',       UserController::class, 'profile');
Router::add('POST', 'user.profile',       UserController::class, 'updateProfile');
Router::add('POST', 'user.avatar',        UserController::class, 'avatarUpload');
Router::add('GET',  'user.activity',      UserController::class, 'activity');
Router::add('GET',  'user.contributions', UserController::class, 'contributions');

// 注册码（管理）
Router::add('GET',  'regcode.list',     RegCodeController::class, 'list');
Router::add('POST', 'regcode.generate', RegCodeController::class, 'generate');
Router::add('POST', 'regcode.delete',   RegCodeController::class, 'delete');
Router::add('POST', 'regcode.destroy',  RegCodeController::class, 'destroy');

// 页面
Router::add('GET',  'page.index',      PageController::class, 'index');
Router::add('GET',  'page.get',        PageController::class, 'get');
Router::add('GET',  'page.list',       PageController::class, 'list');
Router::add('GET',  'page.recent',     PageController::class, 'recent');
Router::add('GET',  'page.search',     PageController::class, 'search');
Router::add('GET',  'page.revisions',  PageController::class, 'revisions');
Router::add('GET',  'page.diff',       PageController::class, 'diff');
Router::add('GET',  'page.backlinks',  PageController::class, 'backlinks');
Router::add('GET',  'page.contributors', PageController::class, 'contributorsEndpoint');
Router::add('GET',  'page.similar',    PageController::class, 'similar');
Router::add('GET',  'page.perms',      PageController::class, 'perms');
Router::add('POST', 'page.save',       PageController::class, 'save');
Router::add('POST', 'page.update-acl', PageController::class, 'updateAcl');
Router::add('POST', 'page.delete',     PageController::class, 'delete');
Router::add('POST', 'page.revert',     PageController::class, 'revert');
Router::add('POST', 'page.delete-revision', PageController::class, 'deleteRevision');

// 订阅
Router::add('GET',  'watch.status', WatchController::class, 'status');
Router::add('GET',  'watch.list',   WatchController::class, 'list');
Router::add('POST', 'watch.add',    WatchController::class, 'add');
Router::add('POST', 'watch.remove', WatchController::class, 'remove');

// RSS
Router::add('GET', 'feed.rss', FeedController::class, 'rss');

// 工作区（沉浸式页面编辑器，仅管理员）
Router::add('GET',  'workspace.get',   WorkspaceController::class, 'get');
Router::add('POST', 'workspace.save',  WorkspaceController::class, 'save');

// 管理后台
Router::add('GET',  'admin.stats',         AdminController::class, 'stats');
Router::add('GET',  'admin.settings',      AdminController::class, 'settingsGet');
Router::add('POST', 'admin.settings',      AdminController::class, 'settingsSave');
Router::add('GET',  'admin.backup',        AdminController::class, 'backup');
Router::add('POST', 'admin.restore',       AdminController::class, 'restore');
Router::add('GET',  'admin.pages',         AdminController::class, 'pages');
Router::add('GET',  'admin.version-check', AdminController::class, 'versionCheck');
Router::add('POST', 'admin.update',        AdminController::class, 'update');
Router::add('GET',  'admin.update-status', AdminController::class, 'updateStatus');

Router::dispatch();
