import React, { cloneElement, FC, ReactElement } from "react";
import { Form, Button } from "react-bootstrap";
import { Controller, UseFormMethods } from "react-hook-form";
import { PageState, useCurrentRouter } from "../services/router.service";
import { currencyCodeToSymbol } from "../schema";
import { get as getEnv } from "../env";

interface ContinueButtonProps {
  disabled?: boolean;
  text?: string;
  onClick?(event: React.MouseEvent<HTMLButtonElement>): void;
}

export const ContinueButton: FC<ContinueButtonProps> = ({
  disabled,
  text,
  onClick
}) => (
  <Button
    className="form-section-addon d-flex align-items-center text-xxs"
    type="submit"
    onClick={onClick}
    disabled={disabled}
  >
    {text || "Continue"}
  </Button>
);

interface RadioPanelProps {
  value: string;
  form: UseFormMethods<any>;
  name: string;
  label: string;
  description?: string;
  valueMeta?: string;
  className?: string;
}

export const RadioPanel: FC<RadioPanelProps> = ({
  value,
  valueMeta,
  description,
  form,
  name,
  label,
  className = ""
}) => (
  <Controller
    name={name}
    control={form?.control}
    render={({ onChange }) => {
      const currentValue = form?.watch(name);
      const checked = value === currentValue;
      console.log('cv', currentValue)

      return (
        <Form.Label
          className={
            "d-flex flex-row radio-panel" +
            className +
            (checked ? " selected" : "")
          }
        >
          <Form.Check
            custom
            inline
            id={name + "-" + value}
            type="radio"
            checked={checked}
            className={checked ? "checked" : undefined}
            onChange={(e) => onChange(value)}
          />
          <div className="flex-grow-1">
            <h3 className="radio-panel-label mb-0">{label}</h3>
            <div className="radio-panel-description">{description}</div>
            {valueMeta && <span className="float-right">{valueMeta}</span>}
          </div>
        </Form.Label>
      );
    }}
  />
);

interface PlanRadioPanelProps {
  label: string;
  form: UseFormMethods<any>;
  name: string;
  className?: string;
  plans: {
    value: string;
    label: string;
    description: string;
    amount: number;
    currency: string;
    allowCustomAmount: boolean;
    frequency: string;
  }[];
}

export const PlanRadioPanel: FC<PlanRadioPanelProps> = ({
  label,
  plans,
  form,
  name,
  className = ""
}) => {
  const hideZeroPriceDisplay = Boolean(getEnv("HIDE_ZERO_PRICE_DISPLAY"));

  return (
    <Controller
      name={name}
      control={form?.control}
      render={({ onChange }) => {
        const currentValue = form?.watch(name);
        const currentPlan =
          plans.find((p) => p.value === currentValue) || plans[0];
        const checked = currentPlan.value === currentValue;

        const makePriceLabel = ({
          currency,
          amount,
          frequency
        }: {
          currency: string;
          amount: number;
          frequency: string;
        }) => {
          if (hideZeroPriceDisplay && Number(amount) === 0) {
            return "";
          }
          const currencySymbol = currencyCodeToSymbol(currency);
          return `${currencySymbol}${amount}, ${frequency}`;
        };

        const onChangeClearCustom = (value: string) => {
          const nextPlan = plans.find((p) => p.value === value) || plans[0];
          const nextAmount = nextPlan.allowCustomAmount ? nextPlan.amount : "";
          onChange(value);
          // Update the form value in a timeout to wait for the <input> for the
          // custom amount to be rendered.
          setTimeout(() => {
            form?.setValue("customMembershipAmount", nextAmount);
          });
        };

        return (
          <Form.Label
            className={
              "d-flex flex-row radio-panel" +
              className +
              (checked ? " selected" : "")
            }
          >
            <Form.Check
              custom
              inline
              id={name + "-" + label}
              type="radio"
              checked={checked}
              className={checked ? "checked" : undefined}
              onChange={() => onChangeClearCustom(currentPlan.value)}
            />
            <div className="flex-grow-1">
              <h3 className="radio-panel-label mb-0">
                {label}

                {plans.length === 1 ? (
                  currentPlan.allowCustomAmount ? (
                    <div className="d-flex align-items-center mb-2">
                      {currencyCodeToSymbol(currentPlan.currency)}
                      <FormItem
                        name="customMembershipAmount"
                        form={form}
                        required={checked && currentPlan.allowCustomAmount}
                        className="mb-0 ml-1"
                      >
                        <Form.Control
                          id={`${currentPlan.value}-amount`}
                          type="number"
                          min={currentPlan.amount || 1}
                          max="1000"
                        />
                      </FormItem>
                    </div>
                  ) : (
                    <div className="float-right ml-2">
                      {makePriceLabel(currentPlan)}
                    </div>
                  )
                ) : (
                  <>
                    <Form.Control
                      as="select"
                      custom
                      className="form-control pr-4 my-2"
                      value={currentValue}
                      onChange={(e) => onChangeClearCustom(e.target.value)}
                    >
                      {plans.map((p) => (
                        <option key={p.value} value={p.value}>
                          {`${p.currency}, ${p.frequency}`}
                        </option>
                      ))}
                    </Form.Control>

                    {!checked || !currentPlan.allowCustomAmount ? (
                      (hideZeroPriceDisplay && Number(currentPlan.amount) === 0) 
                        ? ""
                        : `${currencyCodeToSymbol(currentPlan.currency)}${currentPlan.amount}`
                    ) : (
                      <div
                        className={
                          checked && currentPlan.allowCustomAmount
                            ? "d-flex align-items-center mb-2"
                            : "d-none"
                        }
                      >
                        {currencyCodeToSymbol(currentPlan.currency)}
                        <FormItem
                          name="customMembershipAmount"
                          form={form}
                          required={checked && currentPlan.allowCustomAmount}
                          className="mb-0 ml-1"
                        >
                          <Form.Control
                            id={`${currentPlan.value}-amount`}
                            type="number"
                            min={currentPlan.amount || 1}
                            max="1000"
                          />
                        </FormItem>
                      </div>
                    )}
                  </>
                )}
              </h3>

              <div className="radio-panel-description">
                {currentPlan.description}
              </div>
            </div>
          </Form.Label>
        );
      }}
    />
  );
};

interface FormItemProps {
  name: string;
  className?: string;
  label?: string;
  form: UseFormMethods<any>;
  children: ReactElement | ReactElement[];
  required?: Boolean;
  after?: ReactElement;
  style?: React.CSSProperties;
}

export const FormItem: FC<FormItemProps> = ({
  name,
  label,
  form,
  className,
  children,
  after,
  style,
  required
}) => {
  const childArr: ReactElement[] = (
    Array.isArray(children) ? children : [children]
  ) as ReactElement[];
  const error = form.errors[name]?.message;
  if (error) {
    console.log(error);
  }

  const isInvalid = !!error;
  const isValid = form.formState.isSubmitted && !error;

  return (
    <Form.Group className={className} style={style}>
      {label && (
        <Form.Label htmlFor={name}>
          {label}{" "}
          {required && (
            <>
              <span aria-hidden="true">*</span>{" "}
              <div className="sr-only">required</div>
            </>
          )}
        </Form.Label>
      )}
      {childArr.map((child, i) =>
        cloneElement(child, {
          name,
          ref: form.register,
          id: child.props.id || name,
          isInvalid,
          isValid,
          key: i,
          required
        })
      )}
      {isInvalid && (
        <Form.Control.Feedback type="invalid">{error}</Form.Control.Feedback>
      )}
      {after}
    </Form.Group>
  );
};

interface DetailPanelProps {
  label: string;
  action: Partial<PageState>;
}

export const DetailsCard: FC = (props) => (
  <div className="details-card d-table bg-white w-100" {...props} />
);

export const DetailPanel: FC<DetailPanelProps> = ({
  label,
  children,
  action
}) => {
  const router = useCurrentRouter();
  const onRequestChange = (e: React.MouseEvent) => {
    e.preventDefault();
    router.setState({ ...router.state, ...action });
  };

  return (
    <div className="details-panel d-table-row text-xs summary-row">
      <div className="d-table-cell p-2 p-md-3 w-md-25 text-xxs text-nowrap">
        {label.replace(/ /g, " ")}
      </div>
      <div className="d-table-cell p-2 p-md-3 w-100 w-md-50 text-xxs">
        {children}
      </div>
      <div className="d-table-cell p-2 p-md-3 w-md-25 text-xxs text-right">
        <a onClick={onRequestChange} href="#">
          Change
        </a>
      </div>
    </div>
  );
};
