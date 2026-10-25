@props(['records', 'covers', 'webPreview' => false, 'mailMessage'])
<table width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="digest-masthead" bgcolor="#d84435"><a href="{{ config('app.url') }}">Plaat Club.</a></td>
</tr>
<tr>
<td class="digest-poster" bgcolor="#d84435">
<h1 class="digest-heading">Sunday<br />Morning.</h1>
<p>Records your friends added to their collections this week.</p>
</td>
</tr>
@foreach($records as $record)
<tr>
<td class="digest-row" bgcolor="{{ $loop->even ? '#d3c0ea' : '#d9ed92' }}" style="background-color: {{ $loop->even ? '#d3c0ea' : '#d9ed92' }};">
<table width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="digest-art" width="168" valign="top">
@if(isset($covers[$record->id]))
<a href="{{ $record->url() }}"><img class="digest-cover" src="{{ $webPreview ? 'data:image/jpeg;base64,'.base64_encode($covers[$record->id]) : $mailMessage->embedData($covers[$record->id], 'sleeve-'.$record->id.'.jpg', 'image/jpeg') }}" width="144" height="144" alt="Album cover: {{ $record->payload['displayTitle'] }}" /></a>
@else
<table class="digest-missing" width="144" height="144" cellpadding="0" cellspacing="0" role="presentation"><tr><td align="center" valign="middle">NO SLEEVE.<br />STILL GOOD.</td></tr></table>
@endif
</td>
<td class="digest-details" valign="top">
<p class="digest-owner">{{ $record->user->name }} added</p>
<h2 class="digest-title"><a href="{{ $record->url() }}">{{ $record->payload['displayTitle'] }}</a></h2>
<p class="digest-artist">{{ $record->payload['artist'] }}</p>
<p class="digest-meta">@if($record->formats()){{ implode(' / ', $record->formats()) }} · @endif{{ $record->added_at->timezone('Europe/Brussels')->format('j M') }}</p>
</td>
</tr>
</table>
</td>
</tr>
@endforeach
<tr>
<td class="digest-signoff" bgcolor="#292a22">
<a class="digest-button" href="{{ route('feed') }}">See friends’ additions</a>
</td>
</tr>
<tr>
<td class="digest-footer" bgcolor="#fff9e8">
<a href="{{ route('settings') }}">Turn off the weekly digest in Settings</a>
</td>
</tr>
</table>
