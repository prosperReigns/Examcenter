from fastapi import APIRouter


router = APIRouter()



@router.get(
    "/public/plans"
)
def available_plans():


    return {

        "plans":[


            {
                "id":"trial",
                "name":"Free Trial",
                "duration":7,
                "unit":"days",
                "price":0,
                "currency":"NGN"
            },


            {
                "id":"6_months",
                "name":"6 Months",
                "duration":6,
                "unit":"months",
                "price":50000,
                "currency":"NGN"
            },


            {
                "id":"12_months",
                "name":"12 Months",
                "duration":12,
                "unit":"months",
                "price":100000,
                "currency":"NGN"
            },


            {
                "id":"24_months",
                "name":"24 Months",
                "duration":24,
                "unit":"months",
                "price":200000,
                "currency":"NGN"
            }

        ]

    }