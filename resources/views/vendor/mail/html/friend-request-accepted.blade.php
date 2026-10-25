@props(['friend', 'url'])
<table width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="request-masthead" bgcolor="#d84435">
<a href="{{ config('app.url') }}">Plaat Club.</a>
</td>
</tr>
<tr>
<td class="request-poster" bgcolor="#d9ed92">
<p class="request-eyebrow">YOUR LISTENING CIRCLE · SIDE B</p>
<table width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td valign="bottom"><p class="request-good">GOOD</p></td>
<td class="request-vinyl-cell" align="right" valign="middle" width="104"><img class="request-vinyl" src="{{ url('images/mail-vinyl.png') }}" width="104" height="104" alt="" /></td>
</tr>
</table>
<h1 class="request-company">COMPANY.</h1>
</td>
</tr>
<tr>
<td class="request-copy" bgcolor="#fff9e8">
<h2>Your circle just got bigger.</h2>
<p><strong>{{ $friend->name }}</strong> ({{ '@'.$friend->handle }}) accepted your friend request on Plaat Club.</p>
@if($url)
<p><a class="request-accept" href="{{ $url }}">See their collection</a></p>
@endif
<p class="request-small">Good music is even better when you have someone to share it with.</p>
</td>
</tr>
<tr>
<td class="request-signoff" bgcolor="#d3c0ea">
<p>GOOD RECORDS.<br />EVEN BETTER COMPANY.</p>
</td>
</tr>
</table>
