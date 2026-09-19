<footer class="py-6 text-center text-xs text-slate-400 dark:text-slate-500">
    <p class="flex items-center justify-center gap-1">
        Mit <x-icon name="heart" class="w-3.5 h-3.5" style="color: var(--brand-primary, #005FD7)" />
        erstellt von XXX
        @isset($currentTenant)
            für {{ $currentTenant->name }}
        @endisset
    </p>
    <p>Copyright &copy; {{ now()->year }} XXX, Alle Rechte vorbehalten</p>
</footer>
