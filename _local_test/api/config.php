<?php
/** NuxtWiki API 配置（由安装向导生成） */

return array (
  'db' => 
  array (
    'driver' => 'sqlite',
    'sqlite_path' => 'E:\\.WindowsData\\网站开发\\NuxtWiki\\api/data/nuxtwiki.sqlite',
  ),
  'site' => 
  array (
    'name' => 'NuxtWiki',
    'description' => '一个基于 Nuxt UI 与 PHP/MySQL 的轻量 Wiki。',
    'base_url' => '',
    'home_tag' => 'HomePage',
    'language' => 'zh-CN',
    'allow_registration' => true,
    'default_read_level' => '0',
    'default_edit_level' => '3',
    'default_history_level' => '3',
    'default_diff_level' => '2',
    'default_backlinks_level' => '3',
    'default_perms_level' => '1',
    'default_contributors_level' => '0',
  ),
  'mail' => 
  array (
    'from' => 'wiki@example.com',
    'transport' => 'mail',
    'smtp' => 
    array (
      'host' => '',
      'port' => 465,
      'user' => '',
      'password' => '',
      'encryption' => 'ssl',
    ),
  ),
  'security' => 
  array (
    'session_name' => 'NUXTWIKI',
    'session_lifetime' => 604800,
  ),
  'uploads' => 
  array (
    'dir' => 'E:\\.WindowsData\\网站开发\\NuxtWiki\\api/uploads',
    'data_dir' => 'E:\\.WindowsData\\网站开发\\NuxtWiki\\api/data',
    'max_size' => 33554432,
    'thumb_max' => 320,
    'allowed' => 
    array (
      0 => 'jpg',
      1 => 'jpeg',
      2 => 'png',
      3 => 'gif',
      4 => 'webp',
      5 => 'svg',
      6 => 'bmp',
      7 => 'pdf',
      8 => 'doc',
      9 => 'docx',
      10 => 'xls',
      11 => 'xlsx',
      12 => 'ppt',
      13 => 'pptx',
      14 => 'txt',
      15 => 'md',
      16 => 'csv',
      17 => 'json',
      18 => 'xml',
      19 => 'zip',
      20 => 'rar',
      21 => '7z',
      22 => 'tar',
      23 => 'gz',
      24 => 'mp3',
      25 => 'mp4',
      26 => 'webm',
      27 => 'ogg',
    ),
  ),
);
