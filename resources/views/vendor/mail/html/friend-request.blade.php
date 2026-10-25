@props(['sender', 'links'])
<table width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="request-masthead" bgcolor="#d84435">
<a href="{{ config('app.url') }}">Plaat Club.</a>
</td>
</tr>
<tr>
<td class="request-poster" bgcolor="#d9ed92">
<p class="request-eyebrow">YOUR LISTENING CIRCLE · SIDE A</p>
<table width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td valign="bottom"><p class="request-good">GOOD</p></td>
<td class="request-vinyl-cell" align="right" valign="middle" width="104"><img class="request-vinyl" src="{{ asset('images/mail-vinyl.png') }}" width="104" height="104" alt="" /></td>
</tr>
</table>
<h1 class="request-company">COMPANY.</h1>
</td>
</tr>
<tr>
<td class="request-copy" bgcolor="#fff9e8">
<h2>A new face in your crate.</h2>
<p><strong>{{ $sender->name }}</strong> ({{ '@'.$sender->handle }}) would like to be your friend on Plaat Club.</p>
<p>Friends can see collections shared with Friends and their new additions. Collections set to Only me stay private.</p>
<p><a class="request-accept" href="{{ $links['accept'] }}">Accept friend request</a></p>
<a class="request-decline" href="{{ $links['decline'] }}">Decline friend request</a>
<p class="request-small">These private links expire in seven days and work without signing in. You’ll confirm your choice on Plaat Club. Don’t forward this email.</p>
</td>
</tr>
<tr>
<td class="request-signoff" bgcolor="#d3c0ea">
<p>GOOD RECORDS.<br />EVEN BETTER COMPANY.</p>
</td>
</tr>
</table>
