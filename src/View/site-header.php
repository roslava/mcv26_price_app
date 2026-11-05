<?php
use Mcv26\Price\AppUrl;
$mainLinks = [
 ['https://mcv26.ru/', 'Главная'],
 [AppUrl::publicPath('/'), 'Услуги и цены'],
 ['https://mcv26.ru/doctors/', 'Наши врачи'],
 ['https://mcv26.ru/news/', 'Новости'],
 ['https://mcv26.ru/poleznye-stati/', 'Полезные статьи'],
 ['https://mcv26.ru/o-klinike/', 'О медцентре'],
];
$subLinks = [
 ['https://mcv26.ru/napravleniya/', 'Направления'],
 ['https://mcv26.ru/cooperation/', 'Сотрудничество'],
 ['https://mcv26.ru/kontakty/', 'Контакты'],
];
?>
<header class="mcv-header"><div class="mcv-header-inner">
 <a class="mcv-logo" href="https://mcv26.ru/"><img src="<?= public_e(AppUrl::assetPath('mcv26_logo_new.svg')) ?>" width="792" height="190" alt="Медицинский центр Власова"></a>
 <div class="mcv-address"><span>г. Ставрополь ул. Чехова, 45а</span><a href="tel:+78652447044">+7 (8652) 44-70-44</a></div>
 <div class="mcv-address"><span>г. Ставрополь, ул. Тухачевского, 26/5</span><a href="tel:+78652477366">+7 (8652) 477-366</a></div>
 <button class="mcv-consult" type="button" data-consult>Получить консультацию</button>
</div></header>
<div class="mcv-nav-marker" aria-hidden="true"></div>
<nav class="mcv-nav" aria-label="Главное меню"><ul class="mcv-nav-list">
 <?php foreach ($mainLinks as $i => [$url, $label]): ?>
 <li<?= $i === 5 ? ' class="mcv-has-sub"' : '' ?>><a href="<?= public_e($url) ?>"<?= $i === 1 ? ' aria-current="page"' : '' ?>><?= public_e($label) ?></a>
 <?php if ($i === 5): ?>
 <button class="mcv-sub-toggle" type="button" aria-label="Подменю о медцентре" aria-expanded="false" aria-controls="mcv-submenu"><svg viewBox="0 0 20 20" aria-hidden="true"><path d="m5 8 5 5 5-5"/></svg></button>
 <ul class="mcv-submenu" id="mcv-submenu"><?php foreach ($subLinks as [$subUrl, $subLabel]): ?><li><a href="<?= public_e($subUrl) ?>"><?= public_e($subLabel) ?></a></li><?php endforeach; ?></ul>
 <?php endif; ?></li>
 <?php endforeach; ?>
</ul></nav>
<button class="mcv-burger" type="button" aria-label="Открыть меню" aria-expanded="false" aria-controls="mcv-mobile-menu"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg></button>
<dialog class="mcv-drawer" id="mcv-mobile-menu" aria-label="Меню сайта">
 <div class="mcv-drawer-top"><span>Меню</span><button type="button" class="mcv-close" data-close aria-label="Закрыть меню">×</button></div>
 <nav aria-label="Мобильное меню"><?php foreach (array_merge($mainLinks, $subLinks) as [$url, $label]): ?><a href="<?= public_e($url) ?>"<?= $label === 'Услуги и цены' ? ' aria-current="page"' : '' ?>><?= public_e($label) ?></a><?php endforeach; ?></nav>
 <div class="mcv-drawer-contacts"><a href="tel:+78652447044">+7 (8652) 44-70-44</a><a href="tel:+78652477366">+7 (8652) 477-366</a></div>
</dialog>
<dialog class="mcv-consult-dialog" aria-labelledby="mcv-consult-title">
 <button type="button" class="mcv-close" data-close aria-label="Закрыть">×</button>
 <h2 id="mcv-consult-title">Получить консультацию</h2>
 <p>Позвоните в удобное для вас отделение:</p>
 <p>ул. Чехова, 45а<br><a href="tel:+78652447044">+7 (8652) 44-70-44</a></p>
 <p>ул. Тухачевского, 26/5<br><a href="tel:+78652477366">+7 (8652) 477-366</a></p>
</dialog>

