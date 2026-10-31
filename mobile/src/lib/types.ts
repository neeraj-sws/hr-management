export type AttendanceSession = {
  id: number;
  work_date: string;
  check_in: string | null;
  check_out: string | null;
  active_seconds: number;
  idle_seconds: number;
  break_seconds: number;
  status: 'working' | 'on_break' | 'completed' | string;
  server_version: number;
  worked_seconds?: number;
  /** Idle time as the website shows it. */
  idle_counted_seconds?: number;
  /** Screen time from the desktop app, the same figures as the website. */
  screen_active_seconds?: number;
  screen_idle_seconds?: number;
  screen_meeting_seconds?: number;
  screen_corrected_seconds?: number;
  screen_untracked_seconds?: number;
  /** What keeps counting each second (the same rule as the website and the desktop app). */
  live?: { running: boolean; state: 'active' | 'idle' | 'meeting' | null; tick_for: number; in_out_seconds: number; break_seconds: number; idle_deducted?: boolean };
  events?: { event_type: string; event_time: string; duration_seconds: number }[];
};

export type Me = {
  id: number;
  name: string;
  email: string;
  roles: string[];
  /** Who the person is, as on the website: Master Admin / HR / Accounts / Manager / Team lead / Employee / Trainee. */
  badges?: { label: string; tone: string }[];
  /** Profile photo (set on the website or in the app), loaded with the sign-in token. */
  photo_url?: string | null;
  /** People reporting to them (the "Team" button in the header). */
  team_size?: number;
  permissions: string[];
  employee: {
    id: number;
    employee_code: string;
    name: string;
    email: string | null;
    department: { id: number; name: string } | null;
    manager: { id: number; name: string } | null;
    joined_at: string | null;
  } | null;
  today: AttendanceSession | null;
  unread_notifications: number;
  is_trainee?: boolean;
  missed_status_days?: string[];
  schedule: { shift: string | null; starts_at: string | null; grace_minutes: number } | null;
  policies: { location_required: boolean; selfie_required?: boolean; away_prompt_minutes: number; attendance_sources?: string[] };
};

export type Task = {
  id: number;
  title: string;
  description: string | null;
  project: { id: number; name: string } | null;
  assignee: { id: number; name: string } | null;
  priority: string;
  status: string;
  estimated_hours: number | null;
  logged_seconds: number;
  due_on: string | null;
  overdue: boolean;
  completed_late: boolean;
  timer_running_since: string | null;
  completed_at: string | null;
  server_version: number;
  work_date?: string | null;
  /** Only with ?date=: time logged on that day. */
  day_seconds?: number;
};

export type LeaveType = { id: number; name: string; paid: boolean; allow_advance: boolean; is_other?: boolean };
export type LeaveBalance = { leave_type: { id: number; name: string; paid: boolean }; entitled: number; accrued: number; adjusted: number; carried: number; used: number; booked: number; remaining: number };
export type LeaveRequest = { id: number; leave_type: string; leave_type_id?: number; other_type?: string | null; duration?: 'full' | 'half' | 'short'; starts_on: string; ends_on: string; days: number; reason: string; status: string; decision_note: string | null };
/** A request for a month's payslip; url opens it (short-lived link) once sent. */
export type PayslipRequest = { id: number; month: string; month_label: string; note: string | null; status: string; decision_note: string | null; decided_by: string | null; decided_at: string | null; url: string | null };
export type Payslip = { id: number; reference: string; issued_at: string; period_start: string; period_end: string; gross: number; net: number; paid: number; balance: number; breakdown?: Record<string, unknown>; payments?: { paid_on: string; amount: number; reference: string | null }[] };
/** One month of the employee's salary (GET /my/salary): earnings and deductions as [label, amount, note]. */
export type SalaryMonth = {
  month: string; label: string; source: 'estimate' | 'payroll' | 'old_sheet'; status: string; up_to?: string; paid_on?: string | null;
  days: { working: number; present: number; paid_leave: number; unpaid_leave: number };
  monthly_salary: number; earnings: [string, number, string | null][]; deductions: [string, number, string | null][];
  gross: number; deductions_total: number; net: number; paid: number; remaining: number; payslip_id: number | null; payslip_url?: string | null; per_day_rate?: number; days_paid?: number;
  shortfall?: Shortfall | null;
};
/** "Why the salary is less than full": full salary, late check-ins (hours and rupees), other cuts, salary for days worked. */
export type Shortfall = {
  rate: number; working: number; full: number; full_label: string; earned: number; estimate: boolean; hourly: number | null; day_hours: string | null; late_cut: number;
  late_title?: string;
  extra?: { amount: number; days: number; hours: string; dates: string } | null;
  after?: { label: string; amount: number; detail: string | null }[];
  added?: { label: string; amount: number; detail: string | null }[];
  net?: number;
  pending_fines?: { label: string; amount: number; detail: string }[];
  late: { date: string; day: string; in: string; leave?: string | null; worked: string; of: string; short: string; paid: number; cut: number }[];
  lines: { label: string; detail: string | null; days: number; amount: number }[];
};
export type Notification = { id: string; kind: string | null; message: string; read_at: string | null; created_at: string };
/** A notification as the server presents it (same icons and colours as the website). */
export type NotificationItem = Notification & {
  record_id: number | null; type: string; tag: string; app_icon: string; bg: string; fg: string; chip: string;
  actor: string | null; parts: { text: string; bold: boolean }[]; title: string; details: string | null; quote: string | null;
  unread: boolean; time: string; day: 'Today' | 'Yesterday' | 'Earlier'; needs_action: boolean; approval_id: number | null; decision: string | null; url: string | null;
};
export type Approval = { id: number; type: string; label: string; summary: string; created_at: string };
export type Paginated<T> = { data: T[]; total: number; current_page: number; last_page: number };
/** A fine, with the employee's reason and HR / admin's decision on it. */
export type Fine = {
  id: number; date: string; amount: number; reason: string; status: string; late_fine: boolean; can_give_reason: boolean;
  decision_note: string | null; decided_by: string | null; decided_at: string | null;
  my_reason: { reason: string; sent_at: string | null; status: string; decided_by: string | null; decided_at: string | null; note: string | null; paid_back_next_payroll: boolean } | null;
};
export type LateArrival = { id: number; work_date: string; expected_at: string; reason: string; status: string; decision_note: string | null };
export type NightWork = { id: number; work_date: string; starts_at: string; ends_at: string; minutes: number; tasks: { id: number; title: string }[]; work_done: string; status: string; decision_note: string | null };
export type Correction = { id: number; work_date: string; check_in: string | null; check_out: string | null; reason: string; status: string; decision_note: string | null };
export type ExpenseClaim = { id: number; category: string; spent_on: string; amount: number; description: string; has_receipt: boolean; status: string; decision_note: string | null };
export type Announcement = { id: number; title: string; body: string; pinned: boolean; published_at: string; author: string | null };
export type Celebration = { employee: string; type: 'birthday' | 'anniversary'; date: string; years: number | null; today: boolean };
export type Holiday = { date: string; name: string };
export type Training = { id: number; title: string; description: string | null; category: string | null; duration_hours: number | null; material_url: string | null; mandatory: boolean; due_on: string | null; status: string; completed_at: string | null };
