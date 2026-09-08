<section aria-label="Bildirishnomalar" aria-live="polite" class="notification-card" hidden="" id="notification-card">
<header class="notification-card__header">
<div>
<p class="eyebrow">BILDIRISHNOMALAR</p>
<h2>So‘nggi yangiliklar</h2>
</div>
<button class="notification-card__clear" data-action="mark-notifications-read" type="button">Hammasini o‘qildi</button>
</header>
<div class="notification-card__list" id="notification-list"></div>
<footer class="notification-card__footer">
<a href="{{ route('tasks.index') }}">Barcha bildirishnomalarni ko‘rish →</a>
</footer>
</section>
<template id="notification-item-template">
<article class="notification-item" data-notification-id="" data-notification-read="false">
<span aria-hidden="true" class="notification-item__indicator"></span>
<div class="notification-item__content">
<strong data-field="title"></strong>
<p data-field="message"></p>
<time data-field="time"></time>
</div>
<button aria-label="Bildirishnomani yopish" class="notification-item__dismiss" data-action="dismiss-notification" type="button">×</button>
</article>
</template>
