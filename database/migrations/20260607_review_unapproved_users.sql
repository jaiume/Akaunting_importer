-- Review unapproved users created before/around the login hardening.
-- These users cannot receive login links while is_approved = 0.

SELECT
  u.user_id,
  u.email,
  u.created_at,
  COUNT(lt.login_token_id) AS login_token_count,
  MAX(lt.created_at) AS last_login_token_at
FROM `users` u
LEFT JOIN `login_tokens` lt ON lt.user_id = u.user_id
WHERE u.is_approved = 0
GROUP BY u.user_id, u.email, u.created_at
ORDER BY u.created_at DESC;

-- After review, delete only clearly bogus users with no legitimate related data.
-- Keep this commented until the target user_id values are reviewed.
--
-- DELETE FROM `users`
-- WHERE `is_approved` = 0
--   AND `user_id` IN (...);
