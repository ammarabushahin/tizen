# XA durable cPanel scheduler

هذه النسخة هي المشغل الأساسي المقترح لـ XAnalytica بدل الاعتماد على GitHub Actions للجدولة.

## كيف تعمل

المواعيد المنطقية هي:

- 01:00
- 05:00
- 09:00
- 13:00
- 17:00
- 21:00

بتوقيت Europe/Stockholm.

لكن Cron نفسه يعمل كل 5 دقائق. السكربت يقرأ من MySQL آخر موعد نجح فعلياً:

- إذا الموعد الحالي نجح: لا يفعل شيئاً.
- إذا الموعد فشل: يعيد نفس الموعد في كل فحص لاحق حتى ينجح.
- إذا السيرفر توقف وفاتت عدة مواعيد: ينفذ أقدم موعد ناقص أولاً ثم يلحق الباقي.
- لا يعتمد على توقيت سيرفر cPanel ولا على بدء Cron بالدقيقة 00.
- يستخدم File Lock وMySQL GET_LOCK لمنع تشغيلين متداخلين.
- كل طلب HTTP transient يعاد تلقائياً حتى 3 مرات.
- النجاح النهائي يتطلب issued=33 وfailed=0.

## الملفات

- cron.php: المشغل الرئيسي، CLI فقط.
- config.example.php: انسخه إلى config.php وأدخل بيانات XAnalytica وMySQL.
- schema.sql: جداول تتبع المواعيد والمحاولات والطلبات.

## الموقع المفضل

ضع المجلد خارج public_html:

/home/CPANEL_USER/xa/

واجعل صلاحيات config.php هي 600.

## التثبيت

1. أنشئ قاعدة MySQL ومستخدماً من cPanel.
2. استورد schema.sql في phpMyAdmin.
3. انسخ config.example.php إلى config.php وعدل البيانات.
4. اختبر:

php -q /home/CPANEL_USER/xa/cron.php --force

النتيجة المطلوبة:

{"status":"completed","issued":33,"failed":0,...}

5. بعد نجاح الاختبار أضف Cron:

*/5 * * * * /usr/local/bin/php -q /home/CPANEL_USER/xa/cron.php >> /home/CPANEL_USER/xa/logs/cron.log 2>&1

إذا مسار PHP مختلف في الاستضافة، استخدم المسار الذي يظهر من:

which php

## الانتقال من GitHub

لا توقف GitHub قبل نجاح اختبار يدوي من cPanel ثم نجاح موعد مجدول واحد على الأقل.

بعد ذلك عطّل schedule في GitHub واترك workflow للتشغيل اليدوي/الاحتياطي فقط، حتى لا يحدث تحديث مزدوج.
