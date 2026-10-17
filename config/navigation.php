<?php

/*
| Sidebar definition. Access rules live on the routes themselves (the
| `access:` middleware, plus ReportService for report types); an item is shown
| only when its route exists and the signed-in user passes that same check, so
| the menu never renders a link the user cannot open.
*/

return [
    ['heading' => 'Dashboard', 'items' => [
        ['label' => 'Dashboard', 'route' => 'dashboard', 'icon' => 'home'],
    ]],
    ['heading' => 'Company', 'items' => [
        ['label' => 'Announcements', 'route' => 'company.announcements', 'icon' => 'bell'],
        ['label' => 'Holidays', 'route' => 'company.holidays', 'icon' => 'calendar'],
        ['label' => 'Celebrations', 'route' => 'company.celebrations', 'icon' => 'star'],
        ['label' => 'Org Chart', 'route' => 'company.org-chart', 'icon' => 'team'],
    ]],
    ['heading' => 'People', 'items' => [
        ['label' => 'Employees', 'route' => 'people.employees', 'icon' => 'users'],
        ['label' => 'Departments', 'route' => 'people.departments', 'icon' => 'building'],
        ['label' => 'Teams', 'route' => 'people.teams', 'icon' => 'team'],
        ['label' => 'Managers', 'route' => 'people.managers', 'icon' => 'user-check'],
    ]],
    ['heading' => 'Attendance', 'items' => [
        ['label' => 'My Attendance', 'route' => 'attendance.my', 'icon' => 'clock'],
        ['label' => 'Team Attendance', 'route' => 'attendance.dashboard', 'icon' => 'clipboard'],
        ['label' => 'Attendance', 'route' => 'attendance.records', 'icon' => 'calendar'],
        ['label' => 'Corrections', 'route' => 'attendance.corrections', 'icon' => 'edit'],
        ['label' => 'Leave Conflicts', 'route' => 'attendance.conflicts', 'icon' => 'alert'],
        ['label' => 'Devices', 'route' => 'attendance.devices', 'icon' => 'monitor'],
        ['label' => 'Shifts & Roster', 'route' => 'attendance.shifts', 'icon' => 'layers'],
    ]],
    ['heading' => 'Work', 'items' => [
        ['label' => 'Projects', 'route' => 'work.projects', 'icon' => 'folder'],
        ['label' => 'My Projects', 'route' => 'work.my-projects', 'icon' => 'folder'],
        ['label' => 'Tasks', 'route' => 'work.tasks', 'icon' => 'check-square'],
        ['label' => 'My Tasks', 'route' => 'work.my-tasks', 'icon' => 'list'],
        ['label' => 'Task Timer', 'route' => 'work.timer', 'icon' => 'timer'],
        ['label' => 'Daily Work Status', 'route' => 'work.daily-status', 'icon' => 'edit'],
    ]],
    ['heading' => 'Leave & Time', 'items' => [
        ['label' => 'Leave Requests', 'route' => 'leave.requests', 'icon' => 'calendar'],
        ['label' => 'Leave Balance', 'route' => 'leave.balances', 'icon' => 'pie'],
        ['label' => 'Overtime', 'route' => 'leave.overtime', 'icon' => 'timer'],
    ]],
    ['heading' => 'Expenses', 'items' => [
        ['label' => 'Expense Claims', 'route' => 'expenses.index', 'icon' => 'receipt'],
    ]],
    ['heading' => 'Payroll', 'items' => [
        ['label' => 'Salary', 'route' => 'payroll.salary', 'icon' => 'wallet'],
        ['label' => 'Payroll', 'route' => 'payroll.runs', 'icon' => 'layers'],
        ['label' => 'Payroll Approvals', 'route' => 'payroll.approvals', 'icon' => 'check-circle'],
        ['label' => 'Payslips', 'route' => 'payroll.payslips', 'icon' => 'file'],
        ['label' => 'Advances', 'route' => 'payroll.advances', 'icon' => 'trending'],
        ['label' => 'Payments', 'route' => 'payroll.payments', 'icon' => 'credit-card'],
        ['label' => 'Legacy Salary', 'route' => 'payroll.legacy', 'icon' => 'file'],
    ]],
    ['heading' => 'Performance', 'items' => [
        ['label' => 'Performance', 'route' => 'performance.reviews', 'icon' => 'star'],
        ['label' => 'Productivity', 'route' => 'performance.productivity', 'icon' => 'activity'],
    ]],
    ['heading' => 'Training', 'items' => [
        ['label' => 'My Training', 'route' => 'training.my', 'icon' => 'star'],
        ['label' => 'Courses', 'route' => 'training.courses', 'icon' => 'list'],
    ]],
    ['heading' => 'Recruitment', 'items' => [
        ['label' => 'Job Openings', 'route' => 'recruitment.jobs', 'icon' => 'briefcase'],
        ['label' => 'Candidates', 'route' => 'recruitment.candidates', 'icon' => 'user-plus'],
        ['label' => 'Interviews', 'route' => 'recruitment.interviews', 'icon' => 'calendar'],
    ]],
    ['heading' => 'Compliance', 'items' => [
        ['label' => 'Compliance', 'route' => 'compliance.policies', 'icon' => 'shield'],
        ['label' => 'Penalties', 'route' => 'compliance.penalties', 'icon' => 'alert'],
    ]],
    ['heading' => 'Approvals', 'items' => [
        ['label' => 'Approval Center', 'route' => 'approvals.center', 'icon' => 'inbox'],
        ['label' => 'Pending Approvals', 'route' => 'approvals.pending', 'icon' => 'hourglass'],
        ['label' => 'Approval History', 'route' => 'approvals.history', 'icon' => 'history'],
    ]],
    ['heading' => 'Notes', 'items' => [
        ['label' => 'My Notes', 'route' => 'notes.my', 'icon' => 'note'],
        ['label' => 'Team Notes', 'route' => 'notes.team', 'icon' => 'notes'],
    ]],
    ['heading' => 'Employee Lifecycle', 'items' => [
        ['label' => 'Documents', 'route' => 'lifecycle.documents', 'icon' => 'file'],
        ['label' => 'Assets', 'route' => 'lifecycle.assets', 'icon' => 'monitor'],
        ['label' => 'Onboarding', 'route' => 'lifecycle.onboarding', 'icon' => 'user-plus'],
        ['label' => 'Offboarding', 'route' => 'lifecycle.offboarding', 'icon' => 'user-minus'],
        ['label' => 'Final Settlement', 'route' => 'lifecycle.settlements', 'icon' => 'receipt'],
    ]],
    ['heading' => 'Reports', 'items' => [
        ['label' => 'Attendance', 'route' => 'reports.show', 'params' => ['type' => 'attendance'], 'icon' => 'chart'],
        ['label' => 'Leave', 'route' => 'reports.show', 'params' => ['type' => 'leave'], 'icon' => 'chart'],
        ['label' => 'Tasks', 'route' => 'reports.show', 'params' => ['type' => 'tasks'], 'icon' => 'chart'],
        ['label' => 'Projects', 'route' => 'reports.show', 'params' => ['type' => 'projects'], 'icon' => 'chart'],
        ['label' => 'Overtime', 'route' => 'reports.show', 'params' => ['type' => 'overtime'], 'icon' => 'chart'],
        ['label' => 'Expenses', 'route' => 'reports.show', 'params' => ['type' => 'expenses'], 'icon' => 'chart'],
        ['label' => 'Training', 'route' => 'reports.show', 'params' => ['type' => 'training'], 'icon' => 'chart'],
        ['label' => 'Payroll', 'route' => 'reports.show', 'params' => ['type' => 'payroll'], 'icon' => 'chart'],
        ['label' => 'Salary', 'route' => 'reports.show', 'params' => ['type' => 'salary'], 'icon' => 'chart'],
        ['label' => 'Payments', 'route' => 'reports.show', 'params' => ['type' => 'payments'], 'icon' => 'chart'],
        ['label' => 'Teams', 'route' => 'reports.show', 'params' => ['type' => 'teams'], 'icon' => 'chart'],
        ['label' => 'Performance', 'route' => 'reports.show', 'params' => ['type' => 'performance'], 'icon' => 'chart'],
        ['label' => 'Compliance', 'route' => 'reports.show', 'params' => ['type' => 'compliance'], 'icon' => 'chart'],
        ['label' => 'Audit', 'route' => 'reports.show', 'params' => ['type' => 'audit'], 'icon' => 'chart'],
        ['label' => 'Export Center', 'route' => 'reports.exports', 'icon' => 'download'],
    ]],
    ['heading' => 'Monitoring', 'items' => [
        ['label' => 'Monitoring Policy', 'route' => 'monitoring.policy', 'icon' => 'eye'],
        ['label' => 'Screenshots', 'route' => 'monitoring.screenshots', 'icon' => 'monitor'],
        ['label' => 'Capture Report', 'route' => 'reports.show', 'params' => ['type' => 'screenshots'], 'icon' => 'chart'],
        ['label' => 'App Usage', 'route' => 'reports.show', 'params' => ['type' => 'app_usage'], 'icon' => 'chart'],
        ['label' => 'Window Titles', 'route' => 'reports.show', 'params' => ['type' => 'window_titles'], 'icon' => 'chart'],
    ]],
    ['heading' => 'Security', 'items' => [
        ['label' => 'Credential Vault', 'route' => 'security.vault', 'icon' => 'key'],
        ['label' => 'Audit Logs', 'route' => 'security.audit', 'icon' => 'eye'],
    ]],
    ['heading' => 'System', 'items' => [
        ['label' => 'Notifications', 'route' => 'system.notifications', 'icon' => 'bell'],
        ['label' => 'Roles & Permissions', 'route' => 'system.roles', 'icon' => 'lock'],
        ['label' => 'Settings', 'route' => 'system.settings', 'icon' => 'settings'],
    ]],
];
