<!DOCTYPE html>
<html lang="en">
	<head>

		<meta charset="UTF-8">
        <meta content="width=device-width, initial-scale=1.0" name="viewport">
		<meta name="Description" content="Bootstrap Responsive Admin Web Dashboard HTML5 Template">
		<meta name="Keywords" content=""/>

        @include('Dashboard.layouts.head')

    </head>

    <body>

        @include('Dashboard.layouts.header')
        @include('Dashboard.layouts.sidebar')

        <main id="main" class="main">
            @include('Dashboard.layouts.message')

            @yield('content')

        </main>

        @include('Dashboard.layouts.footer')
        @include('Dashboard.layouts.footer-scripts')

    </body>


</html>

