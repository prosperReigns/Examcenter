from sqlalchemy import select
from app.auth.security import get_password_hash
from app.database.session import SessionLocal
from app.models.customer import Customer

CUSTOMER ={
    "name":"test customer",
    "email": "customer@examcenter.com",
}

def seed():
    db = SessionLocal()
    try:
        result = db.execute(select(Customer).where(Customer.email == CUSTOMER["email"]))

        customer = result.scalar_one_or_none()

        if customer:
            print("customer already exists")
            return 
        customer = Customer(name=CUSTOMER["name"],
                    email=CUSTOMER["email"])
        
        db.add(customer)
        db.commit()

        print("customer created successfully")
        print(f"email: {CUSTOMER['email']}")
    finally:
        db.close()

if __name__ == "__main__":
    seed()














