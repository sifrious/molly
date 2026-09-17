<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Molly') | Molly</title>
    @livewireStyles
    <style>
        :root { color-scheme: light; font: 17px/1.6 system-ui, sans-serif; color: #17202a; background: #f4f5f3; }
        * { box-sizing: border-box; } body { margin: 0; } header, main, footer { max-width: 1100px; margin: auto; padding: 1.25rem; }
        header { border-bottom: 1px solid #bac2ba; } nav { display: flex; flex-wrap: wrap; gap: 1.25rem; } a { color: #174f86; text-underline-offset: .2em; }
        h1 { font-size: 2rem; line-height: 1.2; } h2 { margin-top: 2rem; } label { display: block; font-weight: 650; margin-top: 1.2rem; }
        input, textarea { display: block; width: 100%; font: inherit; padding: .6rem; border: 1px solid #606d61; border-radius: .3rem; background: white; color: #17202a; }
        input[type="radio"] { display: inline-block; width: auto; margin-right: .5rem; }
        textarea { min-height: 8rem; } button, [data-flux-button] { font: inherit; background: #174f40; color: white; border: 1px solid #174f40; padding: .55rem 1rem; border-radius: .3rem; cursor: pointer; min-height: 44px; display: inline-flex; align-items: center; text-decoration: none; }
        :focus-visible { outline: 3px solid #ab4300; outline-offset: 3px; } .actions { display: flex; flex-wrap: wrap; gap: 1rem; margin-top: 1.25rem; } .actions form { margin: 0; }
        .notice { padding: 1rem; border: 1px solid #6a7968; background: #fff; } .errors { border-color: #9a2518; } .hint { margin-top: .25rem; color: #46534b; }
        .evidence-summary { border-left: 4px solid #174f40; padding: .25rem 1rem; margin: 1.5rem 0; background: white; } .report-nav { display: flex; flex-wrap: wrap; gap: 1rem; } .measurement { margin: 1.5rem 0; }
        table { width: 100%; border-collapse: collapse; background: white; } caption { text-align: left; font-weight: 650; padding: .6rem 0; } th, td { text-align: left; vertical-align: top; padding: .75rem; border-bottom: 1px solid #bac2ba; overflow-wrap: anywhere; }
        .table-scroll { overflow-x: auto; } pre { white-space: pre-wrap; overflow-wrap: anywhere; background: white; border: 1px solid #bac2ba; padding: 1rem; } code, pre { font-size: .9rem; } dd { margin-left: 0; overflow-wrap: anywhere; } dt { font-weight: 650; margin-top: .75rem; } .skip { position: absolute; top: -100px; } .skip:focus { top: 0; background: white; padding: 1rem; } summary { cursor: pointer; padding: .6rem 0; } svg { width: 1.2rem; height: 1.2rem; }
    </style>
</head>
<body>
<a class="skip" href="#content">Skip to content</a>
<header><nav aria-label="Molly"><a href="{{ route('molly.tasks.index') }}">Molly tasks</a><a href="{{ route('molly.plans.index') }}">Plans</a><a href="{{ route('molly.tasks.create') }}">Create task</a></nav></header>
<main id="content">
    @if(session('status'))<p class="notice" role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<div class="notice errors" role="alert"><h2>Check the request</h2><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    @yield('content')
</main>
<footer><p>Molly runs code with your local user's permissions. Review changed files and assertions before accepting a result.</p></footer>
@livewireScripts
@fluxScripts
</body>
</html>
