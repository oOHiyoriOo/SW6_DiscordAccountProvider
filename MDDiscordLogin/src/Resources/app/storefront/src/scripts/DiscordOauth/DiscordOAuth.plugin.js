import Plugin from 'src/plugin-system/plugin.class';
import HttpClient from 'src/service/http-client.service';

export default class DiscordOAuth extends Plugin {
    init() {
        this.httpClient = new HttpClient();

        const fragment = new URLSearchParams(window.location.hash.slice(1));
        const [accessToken, tokenType, expires_in] = [fragment.get('access_token'), fragment.get('token_type'), fragment.get('expires_in')];
        const failMessage = "Ah, the stars have briefly veered off course. Fear not, for the cosmic winds shall soon align again. Please retry the spell of login, and the gateway shall unfold before thee."

        setTimeout(() => {
            this.updateStatusLine("Ah, the mystical pathways seem to have entangled with the sands of time. Fear not, for the magic lingers, awaiting your touch to spark once more. Retrace your steps, cast the spell of login anew, and the realms shall unfold before thee.")
        }, 60000)

        if (!accessToken) {
            this.updateStatusLine(failMessage);
        } else {
            const requestData = JSON.stringify({'accessToken': accessToken, 'tokenType': tokenType, 'expires_in': expires_in});

            try {

                this.httpClient.post('/discord/auth', requestData, response => {
                    
                    console.log(response);
                    
                    let data = JSON.parse(response);
                    if (data && !data.error && data.msg === "redirect"){
                        window.location.href = data.url
                    }

                }, 'application/json');

            } catch(err){
                console.error(err)
                this.updateStatusLine(failMessage)
            }
        }
    }

    updateStatusLine(message) {
        const statusLine = document.getElementById('discordOauthStatusLine');
        if (statusLine) {
            statusLine.innerText = message;
        }
    }    
}
