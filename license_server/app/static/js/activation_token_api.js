class ActivationAPI{

    async get(url){

        loader.show();

        try{

            let response=
            await fetch(url);

            return await response.json();

        }

        finally{

            loader.hide();

        }

    }

    async post(
        url,
        data
    ){

        loader.show();

        try{

            let response=
            await fetch(

                url,

                {

                    method:"POST",

                    headers:{

                        "Content-Type":
                        "application/json",

                    },

                    body:
                    JSON.stringify(data),

                }

            );

            return await response.json();

        }

        finally{

            loader.hide();

        }

    }

}

const api=
new ActivationAPI();