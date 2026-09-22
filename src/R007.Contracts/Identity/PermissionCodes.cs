namespace R007.Contracts.Identity;

/// <summary>
/// String constants for permission codes seeded by <c>db/migrations/V0001__initial_schema.sql</c>
/// (architecture/06-roles-permissions.md §2). Kept in <c>R007.Contracts</c>, not the Identity
/// module, so every module can reference a permission code by name (for
/// <c>[RequirePermission]</c>/policy declarations) without depending on
/// <c>R007.Modules.Identity</c> internals — only on this shared contract.
/// </summary>
public static class PermissionCodes
{
    public const string OrderCreate = "order.create";
    public const string OrderLineAdd = "order.line.add";
    public const string OrderLineRemoveUnsent = "order.line.remove_unsent";
    public const string OrderSend = "order.send";
    public const string OrderVoidExecute = "order.void.execute";
    public const string OrderVoidApprove = "order.void.approve";
    public const string OrderDiscountApprove = "order.discount.approve";
    public const string OrderPriceOverrideApprove = "order.price_override.approve";
    public const string OrderCompApprove = "order.comp.approve";
    public const string OrderSettle = "order.settle";
    public const string TabViewOwnFacility = "tab.view_own_facility";
    public const string PrepTicketView = "prep_ticket.view";
    public const string PrepTicketTransition = "prep_ticket.transition";
    public const string PaymentTake = "payment.take";
    public const string PaymentSplit = "payment.split";
    public const string PaymentReversalApprove = "payment.reversal.approve";
    public const string CashSessionOpen = "cash_session.open";
    public const string CashSessionClose = "cash_session.close";
    public const string ReceiptReprint = "receipt.reprint";
    public const string InventoryReceive = "inventory.receive";
    public const string InventoryTransferCreate = "inventory.transfer.create";
    public const string InventoryCountCreate = "inventory.count.create";
    public const string InventoryAdjustmentRequest = "inventory.adjustment.request";
    public const string InventoryAdjustmentApprove = "inventory.adjustment.approve";
    public const string InventoryPurchaseReceiptCreate = "inventory.purchase_receipt.create";
    public const string SupplierManage = "supplier.manage";
    public const string FinanceReportView = "finance.report.view";
    public const string SettlementReconcile = "settlement.reconcile";
    public const string RefundApprove = "refund.approve";
    public const string StaffManage = "staff.manage";
    public const string StaffClockCorrectionApprove = "staff.clock_correction.approve";
    public const string RoleAssignmentManage = "role_assignment.manage";
    public const string FacilityConfigure = "facility.configure";
    public const string PricingManage = "pricing.manage";
    public const string ReportViewAll = "report.view.all";
    public const string DeviceRegister = "device.register";
    public const string DeviceRevoke = "device.revoke";
    public const string DeviceView = "device.view";
    public const string SessionRevoke = "session.revoke";
    public const string SecurityEventView = "security_event.view";
    public const string ConfigManage = "config.manage";
    public const string AuditView = "audit.view";
}

/// <summary>Scope level a permission check is evaluated at (architecture/06 §1).</summary>
public enum ScopeLevel
{
    Organization,
    Site,
    FacilityUnit,
}
