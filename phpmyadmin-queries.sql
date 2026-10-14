-- ============================================================================
-- کوئری‌های کاربردی phpMyAdmin برای پایگاه‌داده فروشگاه واماریز (Vamareys)
-- توجه: اگر پیشوند جداول شما در دیتابیس چیزی غیر از wp_ است، آن را جایگزین کنید.
-- ============================================================================

-- ۱. مشاهده کلیه کاربران دارای نقش «مشتری اقساطی» (installment_customer)
SELECT u.ID, u.user_login, u.user_email, u.display_name, um.meta_value AS capabilities
FROM wp_users u
JOIN wp_usermeta um ON u.ID = um.user_id
WHERE um.meta_key = 'wp_capabilities'
  AND um.meta_value LIKE '%installment_customer%';

-- ۲. ارتقای مستقیم یک کاربر به «مشتری اقساطی» با شناسه کاربر (مثلاً کاربر با شناسه 5)
-- نکته: آرایه سریالایز شده دسترسی‌ها بروز می‌شود
UPDATE wp_usermeta 
SET meta_value = 'a:2:{s:8:"customer";b:1;s:20:"installment_customer";b:1;}' 
WHERE user_id = 5 AND meta_key = 'wp_capabilities';

-- ۳. مشاهده تمامی سفارش‌های اقساطی ثبت شده در واماریز (سفارش‌های اصلی)
SELECT p.ID AS order_id, p.post_date, p.post_status, 
       m_total.meta_value AS total_amount,
       m_plan.meta_value AS installment_meta
FROM wp_posts p
LEFT JOIN wp_postmeta m_total ON p.ID = m_total.post_id AND (m_total.meta_key = '_vamareys_total_order_amount' OR m_total.meta_key = '_vamariz_total_order_amount')
LEFT JOIN wp_postmeta m_plan ON p.ID = m_plan.post_id AND (m_plan.meta_key = '_vamareys_is_installment_order' OR m_plan.meta_key = '_vamariz_is_installment_order')
WHERE m_plan.meta_value = 'yes'
ORDER BY p.ID DESC;

-- ۴. مشاهده تمامی زیرسفارش‌های اقساط (اقساط ماهانه ۲، ۳ و ۴)
SELECT p.ID AS sub_order_id, p.post_parent AS parent_order_id, p.post_status,
       m_step.meta_value AS installment_step,
       m_due.meta_value AS due_timestamp,
       FROM_UNIXTIME(m_due.meta_value) AS due_date_readable
FROM wp_posts p
JOIN wp_postmeta m_step ON p.ID = m_step.post_id AND (m_step.meta_key = '_vamareys_installment_step' OR m_step.meta_key = '_vamariz_installment_step')
LEFT JOIN wp_postmeta m_due ON p.ID = m_due.post_id AND (m_due.meta_key = '_vamareys_due_date' OR m_due.meta_key = '_vamariz_due_date')
ORDER BY p.ID DESC;

-- ۵. مشاهده تمامی دعوت‌نامه‌های صادر شده
SELECT option_value 
FROM wp_options 
WHERE option_name = 'vamareys_invitations' OR option_name = 'vamariz_invitations';

-- ۶. بررسی و پاکسازی لاگ‌های قدیمی یا ترنزینت‌های زمان‌بندی شده
SELECT * FROM wp_options 
WHERE option_name LIKE '%vamareys%' OR option_name LIKE '%vamariz%';
