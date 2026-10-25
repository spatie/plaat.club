@props(['collector', 'recordCount', 'url'])
<table width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="request-masthead" bgcolor="#d84435">
<a href="{{ config('app.url') }}">Plaat Club.</a>
</td>
</tr>
<tr>
<td class="request-poster" bgcolor="#d3c0ea">
<table width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td valign="middle"><h1 class="import-quote">“Celebrate<br />good times,<br />come on!”</h1></td>
<td class="request-vinyl-cell" align="right" valign="middle" width="160"><img class="request-vinyl" src="{{ url('images/mail-celebration.png') }}" width="160" height="160" alt="" /></td>
</tr>
</table>
</td>
</tr>
<tr>
<td class="request-copy" bgcolor="#fff9e8">
<h2>Your collection has landed.</h2>
<p>Hey {{ $collector->name }}, the wait is over! All <strong>{{ $recordCount }} records</strong> from your Discogs collection are now at home on Plaat Club.</p>
<p><a class="request-accept" href="{{ $url }}">View your collection</a></p>
</td>
</tr>
</table>
