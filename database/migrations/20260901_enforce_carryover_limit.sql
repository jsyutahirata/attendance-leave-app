UPDATE leave_grants
SET expires_on = STR_TO_DATE(CONCAT(grant_year + 1, '-12-31'), '%Y-%m-%d')
WHERE expires_on > STR_TO_DATE(CONCAT(grant_year + 1, '-12-31'), '%Y-%m-%d');

