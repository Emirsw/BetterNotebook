-- BetterNotebook: apply to the configured database, without data or default accounts.
CREATE TABLE IF NOT EXISTS `users` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(50) NOT NULL UNIQUE,
  `email` VARCHAR(100) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `full_name` VARCHAR(100) DEFAULT NULL,
  `avatar_url` VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `settings` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `gemini_api_key` TEXT DEFAULT NULL,
  `gemini_model` VARCHAR(100) DEFAULT 'gemini-2.5-flash',
  `ui_language` VARCHAR(2) NOT NULL DEFAULT 'tr',
  `ai_provider` VARCHAR(30) DEFAULT 'gemini',
  `groq_api_key` VARCHAR(255) DEFAULT NULL,
  `groq_model` VARCHAR(100) DEFAULT 'llama-3.3-70b-versatile',
  `openrouter_api_key` VARCHAR(255) DEFAULT NULL,
  `openrouter_model` VARCHAR(100) DEFAULT 'deepseek/deepseek-r1:free',
  `theme_mode` ENUM('dark', 'light') DEFAULT 'dark',
  `current_term` VARCHAR(100) DEFAULT 'Güz Dönemi',
  `target_gpa` DECIMAL(3,2) DEFAULT 3.00,
  `graduation_date` DATE DEFAULT NULL,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `courses` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `course_code` VARCHAR(20) NOT NULL,
  `course_name` VARCHAR(150) NOT NULL,
  `instructor` VARCHAR(100) DEFAULT NULL,
  `classroom` VARCHAR(50) DEFAULT NULL,
  `credits` INT DEFAULT 3,
  `akts` INT DEFAULT 5,
  `color` VARCHAR(20) DEFAULT '#6366f1',
  `min_pass_grade` DECIMAL(5,2) DEFAULT 50.00,
  `max_absenteeism` INT DEFAULT 4,
  `used_absenteeism` INT DEFAULT 0,
  `target_grade` VARCHAR(5) DEFAULT 'AA',
  `status` ENUM('ongoing', 'passed', 'failed') DEFAULT 'ongoing',
  `notes` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `course_schedules` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `course_id` INT NOT NULL,
  `day_of_week` ENUM('Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday') NOT NULL,
  `start_time` TIME NOT NULL,
  `end_time` TIME NOT NULL,
  `classroom` VARCHAR(50) DEFAULT NULL,
  FOREIGN KEY (`course_id`) REFERENCES `courses`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `exams_assignments` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `course_id` INT NOT NULL,
  `title` VARCHAR(150) NOT NULL,
  `type` ENUM('vize', 'final', 'but', 'odev', 'proje', 'quiz') NOT NULL DEFAULT 'vize',
  `weight_percentage` INT DEFAULT 40,
  `due_date` DATETIME NOT NULL,
  `score` DECIMAL(5,2) DEFAULT NULL,
  `target_score` DECIMAL(5,2) DEFAULT 70.00,
  `difficulty` ENUM('easy', 'medium', 'hard') DEFAULT 'medium',
  `status` ENUM('pending', 'completed') DEFAULT 'pending',
  `description` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`course_id`) REFERENCES `courses`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `projects` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `title` VARCHAR(150) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `category` VARCHAR(50) DEFAULT 'Web Geliştirme',
  `tech_stack` VARCHAR(255) DEFAULT 'PHP, MySQL, Tailwind',
  `status` ENUM('idea', 'in_progress', 'review', 'completed', 'deployed') DEFAULT 'in_progress',
  `progress_percent` INT DEFAULT 0,
  `github_url` VARCHAR(255) DEFAULT NULL,
  `live_url` VARCHAR(255) DEFAULT NULL,
  `deadline` DATE DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `project_tasks` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `project_id` INT NOT NULL,
  `title` VARCHAR(200) NOT NULL,
  `status` ENUM('todo', 'in_progress', 'done') DEFAULT 'todo',
  `priority` ENUM('low', 'medium', 'high') DEFAULT 'medium',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `notes` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `course_id` INT DEFAULT NULL,
  `project_id` INT DEFAULT NULL,
  `title` VARCHAR(200) NOT NULL,
  `content` LONGTEXT NOT NULL,
  `tags` VARCHAR(255) DEFAULT NULL,
  `color` VARCHAR(20) DEFAULT '#1e293b',
  `is_pinned` TINYINT(1) DEFAULT 0,
  `is_ai` TINYINT(1) DEFAULT 0,
  `qa_history` LONGTEXT DEFAULT NULL,
  `file_path` VARCHAR(255) DEFAULT NULL,
  `file_type` VARCHAR(50) DEFAULT NULL,
  `file_name` VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`course_id`) REFERENCES `courses`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `study_sessions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `course_id` INT DEFAULT NULL,
  `project_id` INT DEFAULT NULL,
  `duration_minutes` INT NOT NULL,
  `session_date` DATE NOT NULL,
  `notes` VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`course_id`) REFERENCES `courses`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_logs` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `feature_type` VARCHAR(50) NOT NULL, -- 'study_plan', 'exam_prep', 'project_roadmap', 'general'
  `prompt_text` TEXT NOT NULL,
  `response_text` LONGTEXT NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS notebook_pdf_annotations (
        id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, note_id INT NOT NULL,
        source_id VARCHAR(80) NOT NULL, page_no INT NOT NULL, source_stamp CHAR(64) NOT NULL,
        steps_json LONGTEXT NOT NULL, revision INT NOT NULL DEFAULT 1,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY annotation_page(user_id,note_id,source_id,page_no),
        FOREIGN KEY(note_id) REFERENCES notes(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS notebook_media (
        id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, path_key CHAR(64) NOT NULL,
        file_path VARCHAR(255) NOT NULL, canonical_path VARCHAR(255) DEFAULT NULL,
        name VARCHAR(255) NOT NULL, size_bytes BIGINT NOT NULL DEFAULT 0,
        kind VARCHAR(20) NOT NULL DEFAULT 'pdf', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY user_path(user_id,path_key), INDEX(user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS notebook_sources (
        id INT AUTO_INCREMENT PRIMARY KEY, note_id INT NOT NULL, user_id INT NOT NULL,
        name VARCHAR(255) NOT NULL, file_path VARCHAR(255) NOT NULL, size_bytes BIGINT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX(note_id,user_id),
        FOREIGN KEY(note_id) REFERENCES notes(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS notebook_import_items (
        user_id INT NOT NULL, client_id VARCHAR(80) NOT NULL, note_id INT NOT NULL,
        PRIMARY KEY(user_id,client_id), FOREIGN KEY(note_id) REFERENCES notes(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS notebook_summaries (
        id INT AUTO_INCREMENT PRIMARY KEY, note_id INT NOT NULL, user_id INT NOT NULL,
        scope VARCHAR(100) NOT NULL, sources_json LONGTEXT NOT NULL, content LONGTEXT NOT NULL, applied_at DATETIME DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX(note_id,user_id),
        FOREIGN KEY(note_id) REFERENCES notes(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS notebook_activity (
        user_id INT NOT NULL, note_id INT NOT NULL, opened_at DATETIME NOT NULL,
        PRIMARY KEY(user_id,note_id), FOREIGN KEY(note_id) REFERENCES notes(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS notebook_preferences (
        user_id INT PRIMARY KEY, preferences LONGTEXT NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS notebook_study_sessions (
        id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, legacy_id INT NOT NULL,
        client_id VARCHAR(80) NOT NULL, course_name VARCHAR(150) NOT NULL,
        duration_seconds INT NOT NULL, started_at BIGINT NOT NULL, ended_at BIGINT NOT NULL,
        status VARCHAR(20) NOT NULL, UNIQUE KEY(user_id,client_id),
        FOREIGN KEY(legacy_id) REFERENCES study_sessions(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS notebook_schema (
    id TINYINT PRIMARY KEY, version INT NOT NULL, installed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS notebook_trash_files (
    file_path VARCHAR(255) PRIMARY KEY,
    queued_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
