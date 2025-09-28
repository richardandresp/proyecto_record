<?php
function can_edit_hallazgo_fields(string $role): bool {
  return in_array($role, ['admin','auditor'], true);
}
function can_answer_gestion(string $role): bool {
  return in_array($role, ['admin','lider'], true);
}
