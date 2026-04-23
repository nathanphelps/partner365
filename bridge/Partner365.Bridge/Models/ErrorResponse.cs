namespace Partner365.Bridge.Models;

public sealed record ErrorBody(string Code, string Message, string? RequestId);

public sealed record ErrorResponse(ErrorBody Error);
