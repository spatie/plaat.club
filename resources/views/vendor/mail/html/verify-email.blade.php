@props(['email', 'url', 'expires'])
<table width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="request-masthead" bgcolor="#d84435">
<a href="{{ config('app.url') }}">Plaat Club.</a>
</td>
</tr>
<tr>
<td class="request-poster" bgcolor="#d9ed92">
<p class="request-eyebrow">YOUR COLLECTION STARTS HERE · SIDE A</p>
<table width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td valign="bottom"><p class="request-good">ALMOST</p></td>
<td class="request-vinyl-cell" align="right" valign="middle" width="104"><img class="request-vinyl" src="{{ url('images/mail-vinyl.png') }}" width="104" height="104" alt="" /></td>
</tr>
</table>
<h1 class="request-company">IN.</h1>
</td>
</tr>
<tr>
<td class="request-copy" bgcolor="#fff9e8">
<h2>One more spin.</h2>
<p>Your records. Your people. Your next good find. First, let’s make sure <strong>{{ $email }}</strong> is yours.</p>
<p><a class="request-accept" href="{{ $url }}">Verify my email</a></p>
<p class="request-small">This link expires in {{ $expires }} minutes. Didn’t create a Plaat Club account? You can safely ignore this email.</p>
</td>
</tr>
<tr>
<td class="request-signoff" bgcolor="#d3c0ea">
<p>GOOD RECORDS.<br />EVEN BETTER COMPANY.</p>
</td>
</tr>
</table>
