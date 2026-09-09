<section aria-label="Bildirishnomalar" aria-live="polite" class="notification-card" hidden="" id="notification-card">
<header class="notification-card__header">
<div>
<p class="eyebrow">BILDIRISHNOMALAR</p>
<h2>So‘nggi yangiliklar</h2>
</div>
@if (($headerUnreadNotifications ?? 0) > 0)
<button class="notification-card__clear" data-action="mark-notifications-read" type="button">Hammasini o‘qildi</button>
@endif
</header>
<div class="notification-card__list" id="notification-list">
@forelse (($headerNotifications ?? []) as $notification)
<article class="notification-item" data-notification-id="{{ $notification->id }}" data-notification-read="{{ $notification->read ? 'true' : 'false' }}">
<span aria-hidden="true" class="notification-item__indicator"></span>
<div class="notification-item__content">
<strong>{{ $notification->title }}</strong>
<p>{{ $notification->message }}</p>
<time>{{ $notification->created_at?->diffForHumans() }}</time>
</div>
@unless ($notification->read)
<button aria-label="O‘qilgan deb belgilash" class="notification-item__dismiss" data-action="dismiss-notification" type="button">×</button>
@endunless
</article>
@empty
<p class="muted" style="padding: 16px;">Hozircha bildirishnomalar yo‘q.</p>
@endforelse
</div>
<footer class="notification-card__footer">
<a href="{{ route('tasks.index') }}">Barcha bildirishnomalarni ko‘rish →</a>
</footer>
</section>
