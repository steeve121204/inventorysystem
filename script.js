document.addEventListener("DOMContentLoaded", () => {
    const canvas = document.getElementById("captchaCanvas");
    if (canvas) generateCaptcha();
});

function generateCaptcha() {
    const chars = "ABCDEFGHJKLMNPQRSTUVWXYZ123456789";
    let captchaText = "";
    for (let i = 0; i < 5; i++) {
        captchaText += chars.charAt(Math.floor(Math.random() * chars.length));
    }
    document.getElementById("captcha_generated").value = captchaText;

    const canvas = document.getElementById("captchaCanvas");
    const ctx = canvas.getContext("2d");
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    ctx.font = "28px Arial";
    ctx.fillText(captchaText, 20, 35);
}
