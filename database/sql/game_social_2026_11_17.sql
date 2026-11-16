-- =====================================================================================
-- 365 HOME — Mini game Tiến lên + Caro: nhạc nền, sticker, chat, gọi thoại trong trận
-- Cập nhật 17/11/2026 — gồm migration 2026_11_16_000001_create_game_social_tables + 2026_11_17_000001_add_voice_to_game_chat_settings
--
-- File này gồm:
--   1. 5 bảng: cms_game_sound_packs, cms_game_sticker_packs, cms_game_stickers, cms_game_player_preferences, cms_game_chat_settings
--   2. Cột voice_enabled (bật/tắt gọi thoại) — tự thêm nếu server đã import bản SQL ngày 16/11 trước đó
--   3. Đánh dấu 2 migration trên đã chạy (để `php artisan migrate` không chạy lại)
--   4. Cấu hình chat mặc định cho tien_len + caro
--   KHÔNG tạo bộ sticker — sticker do admin tự tải lên ở CMS "Mini game — Sticker".
--
-- YÊU CẦU: code mới đã deploy lên server.
-- Gọi thoại cần thêm: WS server (websocket/server.js) bản mới + coturn (deploy/coturn/turnserver.conf) + TURN_* trong .env Laravel.
-- Chạy lại nhiều lần an toàn: bảng đã có không tạo lại, dòng đã có không chèn trùng, không xoá / ghi đè dữ liệu nào.
-- Tiền tố bảng: "cms_" (giống server). Import vào đúng database của CMS (phpMyAdmin → chọn DB → Import).
-- =====================================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 1;

-- ------------------------------------------------------------------ 1. Bảng

CREATE TABLE IF NOT EXISTS `cms_game_sound_packs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `game` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `key` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `is_default` tinyint(1) NOT NULL DEFAULT '0',
  `sort_order` int NOT NULL DEFAULT '0',
  `cover_image` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sounds` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `cms_game_sound_packs_game_key_unique` (`game`,`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `cms_game_sticker_packs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `icon_image` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `games` json DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `sort_order` int NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `cms_game_sticker_packs_key_unique` (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `cms_game_stickers` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `pack_id` bigint unsigned NOT NULL,
  `image` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sort_order` int NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `cms_game_stickers_pack_id_foreign` (`pack_id`),
  CONSTRAINT `cms_game_stickers_pack_id_foreign` FOREIGN KEY (`pack_id`) REFERENCES `cms_game_sticker_packs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `cms_game_player_preferences` (
  `customer_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `game` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sound_pack` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sfx_enabled` tinyint(1) NOT NULL DEFAULT '1',
  `music_enabled` tinyint(1) NOT NULL DEFAULT '1',
  `sfx_volume` tinyint unsigned NOT NULL DEFAULT '100',
  `music_volume` tinyint unsigned NOT NULL DEFAULT '70',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`customer_id`,`game`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `cms_game_chat_settings` (
  `game` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `chat_enabled` tinyint(1) NOT NULL DEFAULT '1',
  `sticker_enabled` tinyint(1) NOT NULL DEFAULT '1',
  `voice_enabled` tinyint(1) NOT NULL DEFAULT '1',
  `max_length` smallint unsigned NOT NULL DEFAULT '200',
  `message_cooldown_seconds` smallint unsigned NOT NULL DEFAULT '1',
  `sticker_cooldown_seconds` smallint unsigned NOT NULL DEFAULT '2',
  `history_size` smallint unsigned NOT NULL DEFAULT '30',
  `banned_words` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`game`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------ 2. Cột voice_enabled (server đã có bảng từ bản trước)
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cms_game_chat_settings' AND COLUMN_NAME = 'voice_enabled');
SET @sql := IF(@col = 0, 'ALTER TABLE `cms_game_chat_settings` ADD COLUMN `voice_enabled` tinyint(1) NOT NULL DEFAULT 1 AFTER `sticker_enabled`', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------------ 3. Đánh dấu migration đã chạy

INSERT INTO `cms_migrations` (`migration`, `batch`)
SELECT '2026_11_16_000001_create_game_social_tables', t.next_batch FROM (
  SELECT COALESCE(MAX(`batch`), 0) + 1 AS next_batch, SUM(`migration` = '2026_11_16_000001_create_game_social_tables') AS has_row FROM `cms_migrations`
) t WHERE COALESCE(t.has_row, 0) = 0;

INSERT INTO `cms_migrations` (`migration`, `batch`)
SELECT '2026_11_17_000001_add_voice_to_game_chat_settings', t.next_batch FROM (
  SELECT COALESCE(MAX(`batch`), 0) + 1 AS next_batch, SUM(`migration` = '2026_11_17_000001_add_voice_to_game_chat_settings') AS has_row FROM `cms_migrations`
) t WHERE COALESCE(t.has_row, 0) = 0;

-- ------------------------------------------------------------------ 4. Cấu hình chat mặc định
INSERT IGNORE INTO `cms_game_chat_settings` (`game`, `chat_enabled`, `sticker_enabled`, `voice_enabled`, `max_length`, `message_cooldown_seconds`, `sticker_cooldown_seconds`, `history_size`, `banned_words`, `created_at`, `updated_at`) VALUES
  ('tien_len', 1, 1, 1, 200, 1, 2, 30, '[]', NOW(), NOW()),
  ('caro', 1, 1, 1, 200, 1, 2, 30, '[]', NOW(), NOW());
