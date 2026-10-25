@props(['user', 'url'])
<table width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="request-masthead" bgcolor="#d84435">
<a href="{{ config('app.url') }}">Plaat Club.</a>
</td>
</tr>
<tr>
<td class="request-poster" bgcolor="#d9ed92">
<p class="request-eyebrow">THE CLUB IS GROWING · NEW SIGNUP</p>
<table width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td valign="bottom"><p class="request-good">NEW</p></td>
<td class="request-vinyl-cell" align="right" valign="middle" width="104"><img class="request-vinyl" src="{{ url('images/mail-vinyl.png') }}" width="104" height="104" alt="" /></td>
</tr>
</table>
<h1 class="request-company">COMPANY.</h1>
</td>
</tr>
<tr>
<td class="request-copy" bgcolor="#fff9e8">
<h2>Another collector in the club.</h2>
<p>Hey Freek, <strong>{{ $user->name }}</strong> just joined Plaat Club.</p>
<p><strong>Username</strong><br />{{ '@'.$user->handle }}<br /><strong>Email</strong><br /><a href="mailto:{{ $user->email }}">{{ $user->email }}</a></p>
<p><a class="request-accept" href="{{ $url }}">View user in admin</a></p>
<p class="request-small">You’re receiving this because a new account was created on Plaat Club.</p>
</td>
</tr>
<tr>
<td class="request-signoff" bgcolor="#d3c0ea">
<p>GOOD RECORDS.<br />EVEN BETTER COMPANY.</p>
</td>
</tr>
</table>
