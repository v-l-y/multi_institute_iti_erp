import { api } from "../../core/http/client.js";
import { el, openDialog, notice } from "../../shared/ui/index.js";

/** Account security UI: secrets visible only during active, authenticated dialog. */
export function openSecurityCenter() {
  const controller=new AbortController();
  let closed=false;
  const status=el("div",{ "aria-live":"polite" },"Loading security settings…");
  const feedback=el("div",{ "aria-live":"assertive" });
  const secrets=el("div",{ className:"form-feedback" });
  const section=el("div",{ className:"stack-form" },status,feedback,secrets);
  function form(title, fields, path, onSuccess) {
    const inputs=fields.map(([name,label,type="text"])=>el("label",{ className:"field" },
      el("span",{},label),
      el("input",{name,type,required:true,autocomplete:type==="password"?"current-password":"off",
        ...(name.endsWith("code")?{maxlength:12}:{} )})));
    const submit=el("button",{type:"submit",className:"button button-secondary"},title);
    const node=el("form",{className:"stack-form"},
      el("h3",{},title),...inputs,submit);
    node.addEventListener("submit",async event=>{
      event.preventDefault();
      if(closed||!node.reportValidity())return;
      submit.disabled=true;feedback.replaceChildren();
      const values=Object.fromEntries(new FormData(node));
      try{
        const result=await api.post(path,values,{signal:controller.signal});
        if(closed)return;
        node.reset();
        onSuccess?.(result);
        await update();
      }catch(error){
        if(!closed && error?.name!=="AbortError")feedback.replaceChildren(
          notice("Account security request rejected",error.message||"Could not update security settings.","error"));
      }finally{submit.disabled=false;}
    });
    section.append(node);
    return node;
  }
  const enroll=form("Start authenticator enrollment",
    [["current_password","Current password","password"]],
    "/api/v1/auth/mfa/enroll",result=>{
      secrets.replaceChildren(
        el("p",{},"Enter this secret in your authenticator app. This setup secret is shown only now:"),
        el("code",{},result.secret),
        el("p",{},"Authenticator URI (manual setup):"),
        el("code",{},result.otpauth_uri)
      );
    });
  const confirm=form("Confirm authenticator",
    [["otp_code","Six-digit authenticator code"]],
    "/api/v1/auth/mfa/confirm",result=>{
      secrets.replaceChildren(el("strong",{},"Save these recovery codes offline. They will not be shown again."),
        el("ul",{},...result.recovery_codes.map(code=>el("li",{},el("code",{},code)))));
    });
  const disable=form("Disable authenticator (requires password and OTP)",
    [["current_password","Current password","password"],
      ["otp_code","Authenticator code (or use recovery code)"]],
    "/api/v1/auth/mfa/disable",()=>secrets.replaceChildren());
  const startEmail=form("Add verified recovery email",
    [["email","Recovery email","email"],["current_password","Current password","password"]],
    "/api/v1/auth/recovery-email/start",()=>feedback.replaceChildren(
      notice("Verification requested","Check the address for a six-digit verification code.")));
  const confirmEmail=form("Confirm recovery email",
    [["verification_code","Six-digit email verification code"]],
    "/api/v1/auth/recovery-email/confirm",()=>feedback.replaceChildren(
      notice("Verified","Recovery email has been verified.")));

  const dialog=openDialog({
    title:"Account security",
    description:"Authenticator codes and verified recovery email protect your institute account.",
    content:section, actions:[{label:"Close",onClick:node=>node.close()}],
    onClose:()=>{closed=true;controller.abort();secrets.replaceChildren();}
  });
  async function update(){
    try{
      const [mfa,email]=await Promise.all([
        api.get("/api/v1/auth/mfa",{signal:controller.signal}),
        api.get("/api/v1/auth/recovery-email",{signal:controller.signal})
      ]);
      if(closed)return;
      status.replaceChildren(
        el("p",{},"Authenticator: "+(mfa.enabled?"Enabled":"Not enabled")+
          " · Remaining recovery codes: "+mfa.recovery_codes_remaining),
        el("p",{},"Recovery email: "+(email.verified?email.email:"Not verified"))
      );
      enroll.hidden=!!mfa.enabled;
      confirm.hidden=!!mfa.enabled;
      disable.hidden=!mfa.enabled;
      startEmail.hidden=!!email.verified;
      confirmEmail.hidden=!!email.verified;
    }catch(error){
      if(!closed && error?.name!=="AbortError")status.replaceChildren(
        notice("Settings unavailable",error.message||"Could not load account settings.","error"));
    }
  }
  void update();
  return dialog;
}
