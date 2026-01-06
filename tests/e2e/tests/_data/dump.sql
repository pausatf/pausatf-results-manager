-- PAUSATF Results Manager E2E Test Data Dump
-- This file contains initial test data for acceptance tests

-- Test events
INSERT INTO wp_options (option_name, option_value, autoload) VALUES
('pausatf_test_event_1', 'a:5:{s:10:"event_name";s:22:"PA State Championships";s:10:"event_date";s:10:"2024-06-15";s:14:"event_location";s:16:"Philadelphia, PA";s:10:"event_type";s:12:"championship";s:15:"sanction_number";s:11:"24-TEST-001";}', 'yes'),
('pausatf_test_event_2', 'a:5:{s:10:"event_name";s:19:"Philadelphia Classic";s:10:"event_date";s:10:"2024-07-04";s:14:"event_location";s:21:"Franklin Field, PA";s:10:"event_type";s:4:"meet";s:15:"sanction_number";s:11:"24-TEST-002";}', 'yes'),
('pausatf_test_event_3', 'a:5:{s:10:"event_name";s:18:"Pittsburgh Invitational";s:10:"event_date";s:10:"2024-08-01";s:14:"event_location";s:14:"Pittsburgh, PA";s:10:"event_type";s:12:"invitational";s:15:"sanction_number";s:11:"24-TEST-003";}', 'yes')
ON DUPLICATE KEY UPDATE option_value = VALUES(option_value);

-- Test athletes
INSERT INTO wp_options (option_name, option_value, autoload) VALUES
('pausatf_test_athlete_1', 'a:5:{s:10:"first_name";s:4:"John";s:9:"last_name";s:3:"Doe";s:4:"club";s:16:"Philadelphia TC";s:6:"gender";s:1:"M";s:9:"age_group";s:4:"Open";}', 'yes'),
('pausatf_test_athlete_2', 'a:5:{s:10:"first_name";s:4:"Jane";s:9:"last_name";s:5:"Smith";s:4:"club";s:18:"Greater Pittsburgh";s:6:"gender";s:1:"F";s:9:"age_group";s:4:"Open";}', 'yes'),
('pausatf_test_athlete_3', 'a:5:{s:10:"first_name";s:4:"Mike";s:9:"last_name";s:7:"Johnson";s:4:"club";s:14:"Lehigh Valley";s:6:"gender";s:1:"M";s:9:"age_group";s:5:"M40-44";}', 'yes')
ON DUPLICATE KEY UPDATE option_value = VALUES(option_value);

-- Test clubs
INSERT INTO wp_options (option_name, option_value, autoload) VALUES
('pausatf_test_club_1', 'a:4:{s:9:"club_name";s:16:"Philadelphia TC";s:4:"city";s:12:"Philadelphia";s:5:"state";s:2:"PA";s:9:"club_code";s:3:"PTC";}', 'yes'),
('pausatf_test_club_2', 'a:4:{s:9:"club_name";s:18:"Greater Pittsburgh";s:4:"city";s:10:"Pittsburgh";s:5:"state";s:2:"PA";s:9:"club_code";s:3:"GPR";}', 'yes'),
('pausatf_test_club_3', 'a:4:{s:9:"club_name";s:14:"Lehigh Valley";s:4:"city";s:9:"Allentown";s:5:"state";s:2:"PA";s:9:"club_code";s:3:"LVT";}', 'yes')
ON DUPLICATE KEY UPDATE option_value = VALUES(option_value);

-- Enable all features for testing
INSERT INTO wp_options (option_name, option_value, autoload) VALUES
('pausatf_features', 'a:11:{s:16:"event_management";b:1;s:18:"results_management";b:1;s:16:"athlete_profiles";b:1;s:15:"club_management";b:1;s:10:"usatf_rules";b:1;s:18:"records_management";b:1;s:15:"rankings_system";b:1;s:21:"performance_analytics";b:1;s:8:"rest_api";b:1;s:11:"rdf_support";b:1;s:13:"import_export";b:1;}', 'yes')
ON DUPLICATE KEY UPDATE option_value = VALUES(option_value);

-- Plugin settings
INSERT INTO wp_options (option_name, option_value, autoload) VALUES
('pausatf_settings', 'a:3:{s:13:"results_per_page";i:25;s:12:"date_format";s:5:"Y-m-d";s:10:"time_format";s:5:"H:i:s";}', 'yes')
ON DUPLICATE KEY UPDATE option_value = VALUES(option_value);
