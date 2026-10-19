<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0">
<channel>
<title>{!! $title !!}</title>
<link>{!! e($link) !!}</link>
<description>{!! e($description) !!}</description>
{!! implode('', $items) !!}
</channel>
</rss>
