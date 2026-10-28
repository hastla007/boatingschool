{{-- Bug-Review Oberfläche U7: role="menuitem" fehlte (das Menü selbst
     bekam role="menu", siehe components/dropdown.blade.php) und
     focus:outline-none ohne einen Ersatz-Ring machte den Tastaturfokus
     hier unsichtbar. --}}
<a role="menuitem" {{ $attributes->merge(['class' => 'block w-full px-4 py-2 text-start text-sm leading-5 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-indigo-500 focus:bg-gray-100 dark:focus:bg-gray-800 transition duration-150 ease-in-out']) }}>{{ $slot }}</a>
