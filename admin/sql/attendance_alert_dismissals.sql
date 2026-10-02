-- 管理者ダッシュボード「打刻の注意喚起」の確認済み（非表示）記録
-- 管理者ダッシュボードの表示だけに使う。attendance / shifts のデータは一切変更しない。
-- 従業員側の画面（staff/dashboard.php）の表示には影響しない。
-- 注意喚起は「従業員×日付」単位で集計しているため（同日に複数シフトがあってもまとめて1行）、
-- shift_id は持たず (alert_type, employee_id, target_date) で一意とする。
-- （shift_id NULL を UNIQUE に含めると、MariaDB では NULL 同士が重複扱いにならず二重登録を防げないため）

CREATE TABLE attendance_alert_dismissals (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  alert_type ENUM('clock_in','clock_out') NOT NULL COMMENT 'clock_in=出勤忘れ clock_out=退勤忘れ',
  employee_id INT UNSIGNED NOT NULL COMMENT '対象従業員（employees.id）',
  target_date DATE NOT NULL COMMENT '対象の勤務日',
  dismissed_by INT UNSIGNED NOT NULL COMMENT '確認済みにした管理者（employees.id）',
  dismissed_at DATETIME NOT NULL,
  UNIQUE KEY uq_alert (alert_type, employee_id, target_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='打刻の注意喚起の確認済み記録（管理者ダッシュボード用）';
