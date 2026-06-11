CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  name VARCHAR(190) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  plan ENUM('free', 'beta', 'pro') NOT NULL DEFAULT 'free',
  accepted_terms_at DATETIME NULL,
  is_18_confirmed TINYINT(1) NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS user_preferences (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL UNIQUE,
  preferred_leagues_json JSON NOT NULL,
  preferred_markets_json JSON NOT NULL,
  excluded_leagues_json JSON NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_user_preferences_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS leagues (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  external_id INT NOT NULL UNIQUE,
  name VARCHAR(190) NOT NULL,
  country VARCHAR(190) NOT NULL,
  logo VARCHAR(255) NULL,
  season INT NOT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1
);

CREATE TABLE IF NOT EXISTS teams (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  external_id INT NOT NULL UNIQUE,
  name VARCHAR(190) NOT NULL,
  logo VARCHAR(255) NULL,
  country VARCHAR(190) NOT NULL
);

CREATE TABLE IF NOT EXISTS matches (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  external_fixture_id INT NOT NULL UNIQUE,
  league_id INT UNSIGNED NOT NULL,
  home_team_id INT UNSIGNED NOT NULL,
  away_team_id INT UNSIGNED NOT NULL,
  date DATETIME NOT NULL,
  status VARCHAR(50) NOT NULL,
  home_score INT NULL,
  away_score INT NULL,
  raw_data_json JSON NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_matches_date (date),
  INDEX idx_matches_status (status),
  CONSTRAINT fk_matches_league FOREIGN KEY (league_id) REFERENCES leagues(id),
  CONSTRAINT fk_matches_home FOREIGN KEY (home_team_id) REFERENCES teams(id),
  CONSTRAINT fk_matches_away FOREIGN KEY (away_team_id) REFERENCES teams(id)
);

CREATE TABLE IF NOT EXISTS team_stats (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  team_id INT UNSIGNED NOT NULL,
  league_id INT UNSIGNED NOT NULL,
  season INT NOT NULL,
  matches_played INT NOT NULL DEFAULT 0,
  goals_for_avg DECIMAL(6,2) NOT NULL DEFAULT 0,
  goals_against_avg DECIMAL(6,2) NOT NULL DEFAULT 0,
  over_1_5_rate DECIMAL(6,2) NOT NULL DEFAULT 0,
  over_2_5_rate DECIMAL(6,2) NOT NULL DEFAULT 0,
  btts_rate DECIMAL(6,2) NOT NULL DEFAULT 0,
  clean_sheet_rate DECIMAL(6,2) NOT NULL DEFAULT 0,
  failed_to_score_rate DECIMAL(6,2) NOT NULL DEFAULT 0,
  raw_data_json JSON NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_team_stats (team_id, league_id, season),
  CONSTRAINT fk_team_stats_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
  CONSTRAINT fk_team_stats_league FOREIGN KEY (league_id) REFERENCES leagues(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS match_analyses (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  match_id INT UNSIGNED NOT NULL UNIQUE,
  main_tendency TEXT NOT NULL,
  over_1_5_probability INT NOT NULL,
  over_2_5_probability INT NOT NULL,
  btts_probability INT NOT NULL,
  confidence_score INT NOT NULL,
  risk_level ENUM('low', 'medium', 'high') NOT NULL,
  summary TEXT NOT NULL,
  key_factors_json JSON NOT NULL,
  red_flags_json JSON NOT NULL,
  conservative_scenario_json JSON NOT NULL,
  balanced_scenario_json JSON NOT NULL,
  bold_scenario_json JSON NOT NULL,
  disclaimer TEXT NOT NULL,
  model_used VARCHAR(100) NOT NULL,
  raw_ai_response_json JSON NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_match_analyses_match FOREIGN KEY (match_id) REFERENCES matches(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS slip_suggestions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  risk_profile ENUM('conservative', 'balanced', 'bold') NOT NULL,
  market_focus ENUM('goals', 'btts', 'mixed') NOT NULL,
  selections_json JSON NOT NULL,
  global_confidence INT NOT NULL,
  global_risk ENUM('low', 'medium', 'high') NOT NULL,
  explanation TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_slips_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS analysis_results (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  match_analysis_id INT UNSIGNED NOT NULL UNIQUE,
  final_score VARCHAR(50) NOT NULL,
  over_1_5_hit TINYINT(1) NOT NULL DEFAULT 0,
  over_2_5_hit TINYINT(1) NOT NULL DEFAULT 0,
  btts_hit TINYINT(1) NOT NULL DEFAULT 0,
  result_status ENUM('pending', 'settled') NOT NULL DEFAULT 'pending',
  settled_at DATETIME NULL,
  CONSTRAINT fk_analysis_results_analysis FOREIGN KEY (match_analysis_id) REFERENCES match_analyses(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS openai_usage_logs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  feature VARCHAR(100) NOT NULL,
  model VARCHAR(100) NOT NULL,
  input_tokens INT NOT NULL DEFAULT 0,
  output_tokens INT NOT NULL DEFAULT 0,
  estimated_cost_usd DECIMAL(10,6) NOT NULL DEFAULT 0,
  status ENUM('success', 'fallback', 'error') NOT NULL,
  reference_id VARCHAR(100) NULL,
  metadata_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_openai_usage_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS api_error_logs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  service VARCHAR(100) NOT NULL,
  endpoint VARCHAR(255) NOT NULL,
  message TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
