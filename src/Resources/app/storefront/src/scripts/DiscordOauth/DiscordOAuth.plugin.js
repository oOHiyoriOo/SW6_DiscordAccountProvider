import Plugin from 'src/plugin-system/plugin.class';
import HttpClient from 'src/service/http-client.service';

export default class DiscordOAuth extends Plugin {
    init() {
        this.httpClient = new HttpClient();

        const urlParams = new URLSearchParams(window.location.search);
        const code = urlParams.get('code');
        const fragment = new URLSearchParams(window.location.hash.slice(1));
        const accessToken = fragment.get('access_token') || code;
        const [tokenType, expires_in] = [fragment.get('token_type'), fragment.get('expires_in')];
        
        const failMessage = "Ah, the stars have briefly veered off course. Fear not, for the cosmic winds shall soon align again. Please retry the spell of login, and the gateway shall unfold before thee."
        
        setTimeout(() => {
            this.updateStatusLine("Ah, the mystical pathways seem to have entangled with the sands of time. Fear not, for the magic lingers, awaiting your touch to spark once more. Retrace your steps, cast the spell of login anew, and the realms shall unfold before thee.")
        }, 60000)


        let getUrl = window.location;
        let baseUrl = getUrl .protocol + "//" + getUrl.host + "/"

        console.log( `Token: ${accessToken}` )
        const requestData = JSON.stringify({'accessToken': accessToken, 'tokenType': tokenType, 'expires_in': expires_in, 'base': baseUrl});
        
        try {

            this.httpClient.post('/discord/auth', requestData, response => {

                // debug helper. tries to display backend `dd();`
                if( response.startsWith("<")){
                    document.getElementsByTagName('html')[0].innerHTML = response;
                    return;
                }

                let data = JSON.parse(response);
                if (data && !data.error && data.msg === "redirect"){
                    window.location.href = data.url
                }

                if(data && data.error && data.msg){
                    this.updateStatusLine(data.msg)
                }

            }, 'application/json');

        } catch(err){
            console.error(err)
            this.updateStatusLine(failMessage)
        }
    }

    updateStatusLine(message) {
        const statusLine = document.getElementById('discordOauthStatusLine');
        if (statusLine) {
            statusLine.innerText = message;
        }
    }    
}
