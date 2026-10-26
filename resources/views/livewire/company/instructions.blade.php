<div class="ins-page" x-data="{ q: '', open: {} }">
    <div class="page-head"><div><h1>Instructions</h1><p>What everyone must know about attendance, leave, salary, e-mails and the apps. Times and limits are today's company settings.</p></div>
        <button type="button" class="btn btn-sm" onclick="window.print()"><x-icon name="download" class="icon-sm" />Print</button></div>
    <div class="ins-search"><x-icon name="search" class="icon-sm" /><input type="search" x-model="q" placeholder="Search, e.g. half day, leave, payslip" aria-label="Search the instructions"></div>
    <nav class="ins-index" aria-label="Sections">@foreach($sections as $s)<a href="#ins-{{ $s['key'] }}">{{ $s['title'] }}</a>@endforeach</nav>
    <div class="ins-grid">
        @foreach($sections as $s)
            <section class="card ins-card" id="ins-{{ $s['key'] }}" x-show="! q || $el.innerText.toLowerCase().includes(q.toLowerCase())">
                <div class="ins-head"><span class="ins-icon"><x-icon :name="$s['icon']" class="icon-sm" /></span><h2>{{ $s['title'] }}</h2></div>
                <dl class="ins-list">
                    @foreach($s['items'] as [$title, $text])
                        <div x-show="! q || $el.innerText.toLowerCase().includes(q.toLowerCase()) || $el.closest('section').querySelector('h2').innerText.toLowerCase().includes(q.toLowerCase())"><dt>{{ $title }}</dt><dd>{{ $text }}</dd></div>
                    @endforeach
                </dl>
            </section>
        @endforeach
    </div>
    <p class="small muted ins-foot">Questions about any of this: ask HR. Rules can change; this page always shows the current ones.</p>
</div>
