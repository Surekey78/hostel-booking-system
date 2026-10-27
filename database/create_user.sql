CREATE USER IF NOT EXISTS 'hostel_user'@'localhost' IDENTIFIED BY 'Hostel@2026';
GRANT ALL PRIVILEGES ON hostel_booking.* TO 'hostel_user'@'localhost';
FLUSH PRIVILEGES;
